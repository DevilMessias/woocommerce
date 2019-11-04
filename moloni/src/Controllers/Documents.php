<?php

namespace Moloni\Controllers;

use Exception;
use Moloni\Curl;
use Moloni\Error;
use Moloni\Tools;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;

/**
 * Class Documents
 * Used to create or update a Moloni Document
 * @package Moloni\Controllers
 */
class Documents
{
    /** @var array */
    private $company = [];

    /** @var int */
    private $orderId = 0;

    /** @var WC_Order */
    private $order;

    /** @var bool|Error */
    private $error = false;

    /** @var int */
    public $document_id;

    /** @var int */
    private $customer_id;

    /** @var int */
    private $document_set_id;

    /** @var int */
    private $documentId;

    /** @var string */
    private $our_reference = '';

    /** @var string */
    private $your_reference = '';

    /** @var string in Y-m-d */
    private $date;

    /** @var string in Y-m-d */
    private $expiration_date;

    /** @var float */
    private $financial_discount = 0;

    /** @var float */
    private $special_discount = 0;

    /** @var int */
    private $salesman_id = 0;

    /** @var int */
    private $salesman_commission = 0;


    // Delivery parameters being used if the option is set
    private $delivery_datetime;
    private $delivery_method_id = 0;

    private $delivery_departure_address = '';
    private $delivery_departure_city = '';
    private $delivery_departure_zip_code = '';
    private $delivery_departure_country = '';

    private $delivery_destination_address = '';
    private $delivery_destination_city = '';
    private $delivery_destination_country = '';
    private $delivery_destination_zip_code = '';
    private $notes = '';

    private $status = 0;
    private $products;

    private $documentType = 'invoices';

    /**
     * Documents constructor.
     * @param int $orderId
     * @throws Error
     */
    public function __construct($orderId)
    {
        $this->orderId = $orderId;
        $this->order = new WC_Order((int)$orderId);

        if (!defined("DOCUMENT_TYPE")) {
            throw new Error(__("Tipo de documento não definido nas opções"));
        }

        $this->documentType = isset($_GET['document_type']) ? sanitize_text_field($_GET['document_type']) : DOCUMENT_TYPE;
    }

    /**
     * Gets the error object
     * @return bool|Error
     */
    public function getError()
    {
        return $this->error ? $this->error : false;
    }

    /**
     * @return mixed
     * @throws Exception
     */
    public function getDocumentId()
    {
        if ((int)$this->documentId > 0) {
            return $this->documentId;
        } else {
            throw new Exception(__("Document not found"));
        }
    }

    /**
     * @return Documents
     */
    public function createDocument()
    {
        try {
            $this->customer_id = (new OrderCustomer($this->order))->create();
            $this->document_set_id = $this->getDocumentSetId();

            $this->date = date('Y-m-d');
            $this->expiration_date = date('Y-m-d');

            $this->your_reference = "#" . $this->order->get_order_number();

            foreach ($this->order->get_items() as $itemIndex => $orderProduct) {
                /** @var $orderProduct WC_Order_Item_Product */
                $newOrderProduct = new OrderProduct($orderProduct, count($this->products));
                $newOrderProduct->create();

                $refundedValue = $this->order->get_total_refunded_for_item($orderProduct->get_id());
                if ((float)$refundedValue > 0) {
                    $newOrderProduct->setPrice($newOrderProduct->price - (float)$refundedValue);
                }

                $refundedQty = $this->order->get_qty_refunded_for_item($orderProduct->get_id());
                if ((float)$refundedQty > 0) {
                    $newOrderProduct->setQty($newOrderProduct->qty - (float)$refundedQty);
                }

                $this->products[] = $newOrderProduct->mapPropsToValues();

            }

            if ($this->order->get_shipping_method() && (float)$this->order->get_shipping_total() > 0) {
                $newOrderShipping = new OrderShipping($this->order, count($this->products));
                $this->products[] = $newOrderShipping->create()->mapPropsToValues();
            }

            foreach ($this->order->get_fees() as $key => $item) {
                /** @var $item WC_Order_Item_Fee */
                $feePrice = abs($item['line_total']);

                if ($feePrice > 0) {
                    $newOrderFee = new OrderFees($item, count($this->products));
                    $this->products[] = $newOrderFee->create()->mapPropsToValues();

                }
            }

            $this->setShippingInfo();
            $this->setNotes();

            // One last validation
            if ((!isset($_GET['force']) || sanitize_text_field($_GET['force']) !== 'true')) {
                if ($this->isReferencedInDatabase()) {
                    throw new Error(
                        __("O documento da encomenda " . $this->order->get_order_number() . " já foi gerado anteriormente!") .
                        " <a href='admin.php?page=moloni&action=genInvoice&id=" . $this->orderId . "&force=true'>" . __("Gerar novamente") . "</a>"
                    );
                }
            }

            $document = $this->mapPropsToValues();


            $insertedDocument = Curl::simple($this->documentType . '/insert', $document);

            if (!isset($insertedDocument['document_id'])) {
                throw new Error(__("Atenção, houve um erro ao inserir o documento"));
            }

            $this->document_id = $insertedDocument['document_id'];
            add_post_meta($this->orderId, "_moloni_sent", $this->document_id, true);

            $addedDocument = Curl::simple('documents/getOne', ["document_id" => $insertedDocument['document_id']]);

            // If the documents is going to be inserted as closed
            if (defined("DOCUMENT_STATUS") && DOCUMENT_STATUS) {

                // Validate if the document totals match can be closed
                if (((float)$this->order->get_total() - (float)$this->order->get_total_refunded()) !== (float)$addedDocument['net_value']) {
                    $viewUrl = admin_url("admin.php?page=moloni&action=getInvoice&id=" . $this->document_id);
                    throw new Error(
                        __("O documento foi inserido mas os totais não correspondem. ") .
                        '<a href="' . $viewUrl . '" target="_BLANK">Ver documento</a>'
                    );
                }

                $closeDocument = [];
                $closeDocument["document_id"] = $this->document_id;
                $closeDocument['status'] = 1;

                // Send email to the client
                if (defined("EMAIL_SEND") && EMAIL_SEND) {
                    $closeDocument['send_email'] = [];
                    $closeDocument['send_email'][] = [
                        'email' => $this->order->get_billing_email(),
                        'name' => $addedDocument['entity_name'],
                        'msg' => ''
                    ];
                }

                Curl::simple($this->documentType . '/update', $closeDocument);
            }


        } catch (Error $error) {
            $this->document_id = 0;
            $this->error = $error;
        }

        return $this;
    }

    /**
     * Set the document customer notes
     */
    private function setNotes()
    {
        $notes = $this->order->get_customer_order_notes();
        if (!empty($notes)) {
            foreach ($notes as $index => $note) {
                $this->notes .= $note->comment_content;
                if ($index !== count($notes) - 1) {
                    $this->notes .= "<br>";
                }
            }
        }
    }

    /**
     * @throws Error
     */
    public function setShippingInfo()
    {
        if (defined('SHIPPING_INFO') && SHIPPING_INFO) {
            $this->company = Curl::simple("companies/getOne", []);
            $this->delivery_destination_zip_code = $this->order->get_shipping_postcode();
            if ($this->order->get_shipping_country() == "PT") {
                $this->delivery_destination_zip_code = Tools::zipCheck($this->delivery_destination_zip_code);
            }

            $this->delivery_method_id = $this->company['delivery_method_id'];
            $this->delivery_datetime = date("Y-m-d H:i:s");

            $this->delivery_departure_address = $this->company['address'];
            $this->delivery_departure_city = $this->company['city'];
            $this->delivery_departure_zip_code = $this->company['zip_code'];
            $this->delivery_departure_country = $this->company['country_id'];

            $this->delivery_destination_address = $this->order->get_shipping_address_1() . " " . $this->order->get_shipping_address_2();
            $this->delivery_destination_city = $this->order->get_shipping_city();
            $this->delivery_destination_country = Tools::getCountryIdFromCode($this->order->get_shipping_country());
        }
    }

    /**
     * @return int
     * @throws Error
     */
    public function getDocumentSetId()
    {
        if (defined("DOCUMENT_SET_ID") && (int)DOCUMENT_SET_ID > 0) {
            return DOCUMENT_SET_ID;
        } else {
            throw new Error(__("Série de documentos em falta. <br>Por favor seleccione uma série nas opções do plugin", false));
        }
    }

    /**
     * Checks if this document is referenced in database
     * @return bool
     */
    public function isReferencedInDatabase()
    {
        return $this->order->get_meta("_moloni_sent") ? true : false;
    }

    /**
     * Map this object properties to an array to insert/update a moloni document
     * @return array
     */
    private function mapPropsToValues()
    {
        $values = [];
        $values['customer_id'] = $this->customer_id;
        $values['document_set_id'] = $this->document_set_id;
        $values['our_reference'] = $this->our_reference;
        $values['your_reference'] = $this->your_reference;
        $values['date'] = $this->date;
        $values['expiration_date'] = $this->expiration_date;
        $values['financial_discount'] = $this->financial_discount;
        $values['special_discount'] = $this->special_discount;
        $values['salesman_id'] = $this->salesman_id;
        $values['salesman_commission'] = $this->salesman_commission;

        $values['notes'] = $this->notes;
        $values['status'] = $this->status;

        $values['delivery_datetime'] = $this->delivery_datetime;
        $values['delivery_method_id '] = $this->delivery_method_id;

        $values['delivery_departure_address'] = $this->delivery_departure_address;
        $values['delivery_departure_city'] = $this->delivery_departure_city;
        $values['delivery_departure_zip_code'] = $this->delivery_departure_zip_code;
        $values['delivery_departure_country'] = $this->delivery_departure_country;

        $values['delivery_destination_address'] = $this->delivery_destination_address;
        $values['delivery_destination_city'] = $this->delivery_destination_city;
        $values['delivery_destination_zip_code'] = $this->delivery_destination_zip_code;
        $values['delivery_destination_country'] = $this->delivery_destination_country;

        $values['products'] = $this->products;

        return $values;
    }

    /**
     * This method will download a document if it is closed
     * Or it will redirect to the Moloni edit page
     * @param $documentId
     * @return bool
     * @throws Error
     */
    public static function showDocument($documentId)
    {
        $values["document_id"] = $documentId;
        $invoice = Curl::simple("documents/getOne", $values);

        if (!isset($invoice['document_id'])) {
            return false;
        }

        if ($invoice['status'] == 1) {
            $url = Curl::simple("documents/getPDFLink", $values);
            header("Location: " . $url['url']);
        } else {
            switch ($invoice['document_type']['saft_code']) {
                case "FT" :
                default:
                    $typeName = "Faturas";
                    break;
                case "FR" :
                    $typeName = "FaturasRecibo";
                    break;
                case "FS" :
                    $typeName = "FaturaSimplificada";
                    break;
                case "GT" :
                    $typeName = "GuiasTransporte";
                    break;
                case "NEF" :
                    $typeName = "NotasEncomenda";
                    break;
            }

            if (defined("COMPANY_SLUG")) {
                $slug = COMPANY_SLUG;
            } else {
                $meInfo = Curl::simple("companies/getOne", []);
                $slug = $meInfo['slug'];
            }
            header("Location: https://moloni.pt/" . $slug . "/" . $typeName . "/showDetail/" . $invoice['document_id']);
        }
        exit;
    }
}