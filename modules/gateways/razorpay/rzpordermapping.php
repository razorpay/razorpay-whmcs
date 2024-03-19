<?php

class RZPOrderMapping
{
    function createTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS `tblrzpordermapping` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `merchant_order_id` varchar(30) NOT NULL,
                    `razorpay_order_id` varchar(50) NOT NULL,
                     PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8_unicode_ci;";
        $result = mysql_query($sql);
    }

    function insertOrder($merchant_order_id, $razorpay_order_id)
    {
        $insert_sql = "INSERT INTO `tblrzpordermapping` (`merchant_order_id`, `razorpay_order_id`) VALUES ('".$merchant_order_id."', '".$razorpay_order_id."');";
        $result = mysql_query($insert_sql);
    }

    function getRazorpayOrderID($merchant_order_id)
    {
        $sql = "SELECT `razorpay_order_id` FROM `tblrzpordermapping` WHERE merchant_order_id='".$merchant_order_id."' order by `id` desc limit 1;";
        $result = mysql_query($sql);
        $result = mysql_fetch_row($result);
        return $result[0];
    }
}
?>