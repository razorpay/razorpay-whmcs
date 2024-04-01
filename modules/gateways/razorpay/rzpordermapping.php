<?php

use Illuminate\Database\Capsule\Manager as Capsule;

class RZPOrderMapping
{
    private $name;

    function __construct($name)
    {
        $this->name = $name;
    }

    function createTable()
    {
        if (!Capsule::schema()->hasTable('tblrzpordermapping'))
        {
            Capsule::schema()->create('tblrzpordermapping', function($table) {
                $table->increments('id');
                $table->string('merchant_order_id', 20);
                $table->string('razorpay_order_id', 20);
            });
        }
    }

    function insertOrder($merchant_order_id, $razorpay_order_id)
    {
        $merchant_order_id = stripcslashes($merchant_order_id);
        $razorpay_order_id = stripcslashes($razorpay_order_id);

        if (($this->validateMerchantOrderID($merchant_order_id) === false) or
            ($this->validateRazorpayOrderID($razorpay_order_id) === false))
        {
            $error = [
                "merchant_order_id" => $merchant_order_id,
                "razorpay_order_id" => $razorpay_order_id
            ];

            logTransaction($this->name, $error, 'Validation Failure');

            return;
        }
        $insert_array = [
            "merchant_order_id" => $merchant_order_id,
            "razorpay_order_id" => $razorpay_order_id
        ];

        Capsule::table('tblrzpordermapping')->insert($insert_array);
    }

    function getRazorpayOrderID($merchant_order_id)
    {
        $merchant_order_id = stripcslashes($merchant_order_id);

        if (($this->validateMerchantOrderID($merchant_order_id)) === false)
        {
            $error = [
                "merchant_order_id" => $merchant_order_id
            ];

            logTransaction($this->name, $error, 'Validation Failure');

            return;
        }
        $result = Capsule::table('tblrzpordermapping')
            ->select('razorpay_order_id')
            ->where('merchant_order_id', '=', $merchant_order_id)
            ->orderBy('id', 'desc')
            ->first();

        return $result->razorpay_order_id;
    }

    function validateMerchantOrderID($merchant_order_id)
    {
        $pattern = '(^[0-9]+$)';
        return (preg_match($pattern, (string) $merchant_order_id) === 1) ? true : false;
    }

    function validateRazorpayOrderID($razorpay_order_id)
    {
        $pattern = '(^order_[a-zA-Z0-9]+$)';
        return ((preg_match($pattern, (string) $razorpay_order_id) === 1)
        and (strlen(substr($razorpay_order_id, 6)) === 14)) ? true : false;
    }
}
