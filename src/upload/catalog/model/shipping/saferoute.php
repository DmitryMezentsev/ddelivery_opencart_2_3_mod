<?php

class ModelShippingSaferoute extends Model
{
    /**
     * Отправляет запрос на обновление данных заказа на сервер SafeRoute
     *
     * @param $values array Параметры запроса
     * @return array
     */
    private function sendSafeRouteUpdateOrderRequest(array $values)
    {
        $curl = curl_init('https://api.saferoute.ru/v2/widgets/update-order');

        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($values));
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type:application/json',
            'Authorization:Bearer ' . $this->config->get('saferoute_token'),
            'shop-id:' . $this->config->get('saferoute_shop_id'),
        ]);

        $response = json_decode(curl_exec($curl), true);
        curl_close($curl);

        return $response;
    }

    /**
     * Обновляет указанный параметр заказа в БД CMS
     *
     * @param $order_id int|string ID заказа
     * @param $param string Имя параметра в БД
     * @param $value mixed Значение параметра
     */
    private function updateOrder($order_id, $param, $value)
    {
        if (isset($value) && $value)
            $this->db->query("UPDATE `" . DB_PREFIX . "order` SET $param='$value' WHERE order_id='$order_id'");
    }

    /**
     * Возвращает код способа доставки по ID заказа
     *
     * @param $order_id int|string ID заказа
     * @return string
     */
    private function getOrderShipping($order_id)
    {
        $q = $this->db->query("SELECT shipping_code FROM `" . DB_PREFIX . "order` WHERE order_id='$order_id'");
        return $q->row['shipping_code'];
    }

    /**
     * Обновляет saferoute_id заказа и устанавливает флаг, что заказ был перенесен в ЛК SafeRoute
     *
     * @param $order_id int|string ID заказа в БД CMS
     * @param $saferoute_id int|string ID заказа SafeRoute
     */
    private function setOrderSafeRouteCabinetID($order_id, $saferoute_id)
    {
        if ($saferoute_id)
        {
            $this->updateOrder($order_id, 'saferoute_id', $saferoute_id);
            $this->updateOrder($order_id, 'in_saferoute_cabinet', 1);
        }
    }

    /**
     * Разбивает строку с ФИО на массив с фамилией в одной ячейке и именем-отчеством в другой
     *
     * @param $fullName string ФИО
     * @return array
     */
    private function splitFullName($fullName = '')
    {
        $result = [];

        $fullName = preg_split("/\s/", trim($fullName));

        // ФИО
        if (count($fullName) >= 3)
        {
            $result['firstName'] = $fullName[1] . ' ' . $fullName[2];
            $result['lastName'] = $fullName[0];
        }
        // ИФ
        elseif (count($fullName) === 2)
        {
            $result['firstName'] = $fullName[0];
            $result['lastName'] = $fullName[1];
        }
        // И
        else
        {
            $result['firstName'] = $fullName[0];
            $result['lastName'] = '';
        }

        return $result;
    }


    /**
     * Возвращает список возможных статусов заказа
     *
     * @return array
     */
    public function getOrderStatuses()
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_status` WHERE language_id='" . $this->config->get('config_language_id') . "' ORDER BY name");

        $statuses = [];

        foreach ($query->rows as $status)
            $statuses[$status['order_status_id']] = $status['name'];

        return $statuses;
    }

    /**
     * Вызывается сразу после успешного создания заказа
     *
     * @param $order_id int|string ID заказа
     * @return boolean
     */
    public function onOrderCheckoutSuccess($order_id)
    {
        $this->load->model('checkout/order');

        if (!isset($_COOKIE['SROrderData']) || !$order_id) return false;

        $sr_order_data = json_decode(urldecode($_COOKIE['SROrderData']));

        // Сохраняем в заказе тот SafeRoute ID, который был получен виджетом при создании заказа
        $this->updateOrder($order_id, 'saferoute_id', $sr_order_data->id);

        if (isset($_COOKIE['SRWidgetData']) && $this->getOrderShipping($order_id) === 'saferoute.saferoute')
        {
            $sr_widget_data = json_decode(urldecode($_COOKIE['SRWidgetData']));

            // Сохраняем в заказе адрес...
            $this->updateOrder($order_id, 'shipping_address_1', $sr_widget_data->_meta->fullDeliveryAddress);
            // ...город
            if (isset($sr_widget_data->city->name))
                $this->updateOrder($order_id, 'shipping_city', $sr_widget_data->city->name);
            // ...индекс
            if (isset($sr_widget_data->contacts->address->zipCode))
                $this->updateOrder($order_id, 'shipping_postcode', $sr_widget_data->contacts->address->zipCode);
            // ...ФИО
            if (isset($sr_widget_data->contacts->fullName))
            {
                $fullName = $this->splitFullName($sr_widget_data->contacts->fullName);

                $this->updateOrder($order_id, 'shipping_firstname', $fullName['firstName']);
                $this->updateOrder($order_id, 'shipping_lastname', $fullName['lastName']);

                $this->updateOrder($order_id, 'firstname', $fullName['firstName']);
                $this->updateOrder($order_id, 'lastname', $fullName['lastName']);

                /*
                // todo: добавить сохранение этих данных, когда в виджет будет впилен эквайринг
                if (false)
                {
                    $this->updateOrder($order_id, 'payment_firstname', $fullName['firstName']);
                    $this->updateOrder($order_id, 'payment_lastname ', $fullName['lastName']);
                }
                */
            }
            // ...E-mail
            if (isset($sr_widget_data->contacts->email))
                $this->updateOrder($order_id, 'email', $sr_widget_data->contacts->email);
            // ...телефон клиента из виджета
            if (isset($sr_widget_data->contacts->phone))
                $this->updateOrder($order_id, 'telephone', $sr_widget_data->contacts->phone);
            // ...тип доставки
            if (isset($sr_widget_data->delivery->type)) {
                $this->updateOrder($order_id, 'saferoute_delivery_type', $sr_widget_data->delivery->type);
            }
            // ...и название компании доставки
            if (isset($sr_widget_data->delivery->deliveryCompanyName)) {
                $this->updateOrder($order_id, 'saferoute_delivery_company', $sr_widget_data->delivery->deliveryCompanyName);
            }
        }

        setcookie('SRWidgetData', null, -1, '/');
        setcookie('SROrderData', null, -1, '/');

        // Получение заказа в CMS
        $order = $this->model_checkout_order->getOrder($order_id);

        // Отправка запроса к API SafeRoute
        $response = $this->sendSafeRouteUpdateOrderRequest([
            'id'            => $sr_order_data->id,
            'status'        => $order['order_status_id'],
            'cmsId'         => $order_id,
            'paymentMethod' => $order['payment_code'],
        ]);

        if (!empty($response['cabinetId']))
        {
            $this->setOrderSafeRouteCabinetID($order_id, $response['cabinetId']);
            return true;
        }

        return false;
    }

    /**
     * Вызывается при изменении статуса заказа в CMS
     *
     * @param $order_id int|string ID заказа
     * @param $order_status_id int|string ID статуса заказа
     * @return boolean
     */
    public function onOrderStatusUpdate($order_id, $order_status_id)
    {
        $this->load->model('checkout/order');

        $order = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE order_id='" . $order_id . "'")->row;

        // Выполнять запрос к API SafeRoute только если у заказа есть ID SafeRoute
        // и заказ ещё не был передан в Личный кабинет
        if ($order['saferoute_id'] && !$order['in_saferoute_cabinet'])
        {
            // Отправка запроса
            $response = $this->sendSafeRouteUpdateOrderRequest([
                'id'            => $order['saferoute_id'],
                'status'        => $order_status_id,
                'paymentMethod' => $order['payment_code'],
            ]);

            if (!empty($response['cabinetId']))
            {
                $this->setOrderSafeRouteCabinetID($order_id, $response['cabinetId']);
                return true;
            }
        }

        return false;
    }

    /**
     * @param $address array
     * @return array
     */
    public function getQuote($address)
    {
        $this->load->language('shipping/saferoute');

        $query = $this->db->query(
            "SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . $this->config->get('saferoute_geo_zone_id') . "' AND country_id = '" . $address['country_id'] . "' AND (zone_id = '" . $address['zone_id'] . "' OR zone_id = '0')"
        );

        if (!$this->config->get('saferoute_geo_zone_id') || $query->num_rows)
        {
            $cost = 0;

            if (isset($_COOKIE['SRWidgetData']))
            {
                $sr_widget_data = json_decode(urldecode($_COOKIE['SRWidgetData']));

                if ($sr_widget_data)
                {
                    $pay_type_commission = isset($sr_widget_data->payTypeCommission) ? $sr_widget_data->payTypeCommission : 0;
                    $delivery_total_price = isset($sr_widget_data->delivery->totalPrice) ? $sr_widget_data->delivery->totalPrice : 0;

                    $cost = $delivery_total_price + $pay_type_commission;
                }
            }

            return [
                'code'  => 'saferoute',
                'title' => '',
                'quote' => [
                    'saferoute' => [
                        'code'         => 'saferoute.saferoute',
                        'title'        => $this->language->get('text_title'),
                        'cost'         => $cost,
                        'tax_class_id' => 0,
                        'saferoute'	   => 'true',
                        'text'         => "$cost ₽",
                    ],
                ],
                'sort_order' => $this->config->get('saferoute_sort_order'),
                'error' => false,
            ];
        }

        return [];
    }
}
