<?php
/**
 *
 *   Plugin Name:  Moloni
 *   Plugin URI:   https://plugins.moloni.com/woocommerce
 *   Description:  Send your orders automatically to your Moloni invoice software
 *   Version:      0.0.1
 *   Author:       Moloni.com
 *   Author URI:   https://moloni.com
 *   License:      GPL2
 *   License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 *
 */

namespace Moloni\Controllers;

use Moloni\Error;
use Moloni\Tools;
use WC_Order_Item_Product;
use WC_Tax;

class OrderProduct
{

    /** @var int */
    public $product_id = 0;

    /** @var int */
    private $order;

    /**
     * @var WC_Order_Item_Product
     */
    private $product;

    /** @var array */
    private $taxes = [];

    /** @var float */
    public $qty;

    /** @var float */
    public $price;

    /** @var string */
    private $exemption_reason;

    /** @var string */
    private $name;

    /** @var string */
    private $summary;

    /** @var float */
    private $discount;

    /**
     * OrderProduct constructor.
     * @param WC_Order_Item_Product $product
     * @param int $order
     */
    public function __construct($product, $order = 0)
    {
        $this->product = $product;
        $this->order = $order;
    }

    /**
     * @return $this
     * @throws Error
     */
    public function create()
    {
        $this->setPrice();
        $this->setQty();

        $this->name = $this->product->get_name();

        $this->setSummary();
        $this->setProductId();
        $this->setDiscount();
        $this->setTaxes();


        return $this;
    }

    public function setSummary($summary = null)
    {
        if ($summary) {
            $this->summary = $summary;
        } else {
            $this->summary .= $this->getSummaryVariationAttributes();

            if (!empty($this->summary)) {
                $this->summary .= "\n";
            }

            $this->summary .= $this->getSummaryExtraProductOptions();
        }
    }

    /**
     * @return string
     */
    private function getSummaryVariationAttributes()
    {
        $summary = '';

        if ($this->product->get_variation_id() > 0) {
            $product = wc_get_product($this->product->get_variation_id());
            $attributes = $product->get_attributes();
            if (is_array($attributes) && !empty($attributes)) {
                $summary = wc_get_formatted_variation($attributes, true);
            }
        }

        return $summary;
    }

    /**
     * @return string
     */
    private function getSummaryExtraProductOptions()
    {
        $summary = '';
        $checkEPO = $this->product->get_meta("_tmcartepo_data", true);
        $extraProductOptions = maybe_unserialize($checkEPO);

        if ($extraProductOptions && is_array($extraProductOptions)) {
            foreach ($extraProductOptions as $extraProductOption) {
                if (isset($extraProductOption['name']) && isset($extraProductOption['value'])) {

                    if (!empty($summary)) {
                        $summary .= "\n";
                    }

                    $summary .= $extraProductOption['name'] . " " . $extraProductOption['value'];
                }
            }
        }

        return $summary;
    }

    /**
     * @param null|float $price
     */
    public function setPrice($price = null)
    {
        if (!is_null($price)) {
            $this->price = $price;
        } else {
            $this->price = (float)$this->product->get_subtotal() / (float)$this->product->get_quantity();
        }
    }

    /**
     * @param null|float $qty
     */
    public function setQty($qty = null)
    {
        if (!is_null($qty)) {
            $this->qty = $qty;
        } else {
            $this->qty = $this->qty = (float)$this->product->get_quantity();
        }
    }

    /**
     * @throws Error
     */
    private function setProductId()
    {
        $product = new Product($this->product->get_product());

        if (!$product->loadByReference()) {
            $product->create();
        }

        $this->product_id = $product->getProductId();
    }


    /**
     * Set the discount in percentage
     */
    private function setDiscount()
    {
        $this->discount = (float)(100 - (((float)$this->product->get_total() * 100) / (float)$this->product->get_subtotal()));
        $this->discount = $this->discount < 0 ? 0 : $this->discount > 100 ? 100 : $this->discount;
    }

    /**
     * Set the taxes of a product
     * @throws Error
     */
    private function setTaxes()
    {
        $taxes = $this->product->get_taxes();
        foreach ($taxes['subtotal'] as $taxId => $value) {
            if (!empty($value)) {
                $taxRate = preg_replace('/[^0-9.]/', "", WC_Tax::get_rate_percent($taxId));
                if ((float)$taxRate > 0) {
                    $this->taxes[] = $this->setTax($taxRate);
                }
            }
        }

        if (empty($this->taxes)) {
            $this->exemption_reason = defined('EXEMPTION_REASON') ? EXEMPTION_REASON : '';
        }

        return $this;
    }

    /**
     * @param float $taxRate Tax Rate in percentage
     * @return array
     * @throws Error
     */
    private function setTax($taxRate)
    {
        $tax = [];
        $tax['tax_id'] = Tools::getTaxIdFromRate((float)$taxRate);
        $tax['value'] = $taxRate;

        return $tax;
    }

    /**
     * @return array
     */
    public function mapPropsToValues()
    {
        $values = [];

        $values["product_id"] = $this->product_id;
        $values["name"] = $this->name;
        $values["summary"] = $this->summary;
        $values["qty"] = $this->qty;
        $values["price"] = $this->price;
        $values["discount"] = $this->discount;
        $values["order"] = $this->order;
        $values['exemption_reason'] = $this->exemption_reason;
        $values['taxes'] = $this->taxes;

        return $values;
    }
}