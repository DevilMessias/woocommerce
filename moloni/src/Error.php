<?php

namespace Moloni;

use Exception;

class Error extends Exception
{
    /** @var array */
    private $request = [];

    /**
     * Throws a new error with a message and a log from the last request made
     * @param $message
     * @param bool $request
     * @param int $code
     * @param Exception|null $previous
     */
    public function __construct($message, $request = false, $code = 0, Exception $previous = null)
    {
        $this->request = $request ? $request : Curl::getLog();
        parent::__construct($message, $code, $previous);
    }

    public function showError()
    {
        /** @noinspection PhpUnusedLocalVariableInspection */
        $message = $this->getDecodedMessage();

        /** @noinspection PhpUnusedLocalVariableInspection */
        $url = $this->request['url'] ? $this->request['url'] : '';

        /** @noinspection PhpUnusedLocalVariableInspection */
        $sent = $this->request['sent'] ? $this->request['sent'] : [];

        /** @noinspection PhpUnusedLocalVariableInspection */
        $received = $this->request['received'] ? $this->request['received'] : [];

        include MOLONI_TEMPLATE_DIR . 'Messages/DocumentError.php';
    }

    public function getError()
    {
        ob_start();
        $this->showError();
        $result = ob_get_contents();
        ob_end_clean();
        return $result;
    }

    /**
     * Returns the default error message from construct
     * Or tries to translate the error from Moloni API
     * @return string
     */
    public function getDecodedMessage()
    {
        $errorMessage = "<b>" . $this->getMessage() . "</b>";

        if (isset($this->request['received']) && is_array($this->request['received'])) {
            foreach ($this->request['received'] as $line) {
                if (isset($line['description'])) {
                    $errorMessage .= "<br>" . $this->translateMessage($line['description']);
                }
            }
        }

        return $errorMessage;
    }

    /**
     * @param string $message
     * @return string
     */
    private function translateMessage($message)
    {
        switch ($message) {
            case "Field 'category_id' must be integer, greater than 0" :
                $message = __("Verifique por favor se o artigo tem uma categoria associada");
                break;

        }

        return $message;
    }

    public function getRequest()
    {
        return $this->request;
    }
}