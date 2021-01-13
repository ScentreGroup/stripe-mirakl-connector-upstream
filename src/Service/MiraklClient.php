<?php

namespace App\Service;

use App\Exception\InvalidArgumentException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @codeCoverageIgnore
 */
class MiraklClient
{
    const DATE_FORMAT = \DateTime::ISO8601;
    const DATE_FORMAT_INVALID_MESSAGE = 'Unexpected date format, expecting %s, input was %s';

    /**
     * @var HttpClientInterface
     */
    private $client;

    public function __construct(HttpClientInterface $miraklClient)
    {
        $this->client = $miraklClient;
    }

    private function getResponseBody(ResponseInterface $response)
    {
        return json_decode($response->getContent(), true);
    }

    private function getPaginatedResource(string $resource, string $endpoint, array $params = [])
    {
        $options = [ 'query' => array_merge([ 'max' => 10 ], $params) ];

        $response = $this->client->request('GET', $endpoint, $options);
        $agg = $this->getResponseBody($response)[$resource] ?? [];
        while ($next = $this->getNext($response)) {
            $response = $this->client->request('GET', $next);
            $objects = $this->getResponseBody($response)[$resource] ?? [];
            if (empty($objects)) {
                break;
            }

            $agg = array_merge($agg, $objects);
        }

        return $agg;
    }

    private function getPaginatedNestedResource(string $resource, string $nested, string $endpoint)
    {
        $options = [ 'query' => [ 'max' => 10 ] ];

        $response = $this->client->request('GET', $endpoint, $options);
        $agg = $this->getResponseBody($response)[$resource][$nested] ?? [];

        while ($next = $this->getNext($response)) {
            $response = $this->client->request('GET', $next);
            $objects = $this->getResponseBody($response)[$resource][$nested] ?? [];
            if (empty($objects)) {
                break;
            }

            $agg = array_merge($agg, $objects);
        }

        return $agg;
    }

    private function getNext(ResponseInterface $response)
    {
        static $pattern = '/<([^>]+)>;\s*rel="([^"]+)"/';

        $h = $response->getHeaders();
        $links = isset($h['link'][0]) ? explode(',', $h['link'][0]) : [];
        foreach ($links as $link) {
            $res = preg_match($pattern, trim($link), $matches);
            if (false !== $res && 'next' === $matches[2]) {
                return $matches[1];
            }
        }

        return null;
    }

    // return [ order_id => order ]
    private function mapByOrderId(array $orders)
    {
        $orderIds = array_column($orders, 'order_id');
        return array_combine($orderIds, $orders);
    }

    // return [ order_commercial_id => [ order_id => order] ]
    private function mapByCommercialAndOrderId(array $orders)
    {
        $map = [];
        foreach ($orders as $order) {
            $commercialId = $order['commercial_id'] ?? $order['order_commercial_id'];
            if (!isset($map[$commercialId])) {
                $map[$commercialId] = [];
            }

            $map[$commercialId][$order['order_id']] = $order;
        }

        return $map;
    }

    // return [ invoice_id => invoice ]
    private function mapByInvoiceId(array $invoices)
    {
        $invoiceIds = array_map('strval', array_column($invoices, 'invoice_id'));
        return array_combine($invoiceIds, $invoices);
    }

    // OR11
    public function listOrders()
    {
        return $this->mapByOrderId(
            $this->getPaginatedResource('orders', '/api/orders')
        );
    }

    // OR11 by date
    public function listOrdersByDate(string $datetime)
    {
        return $this->mapByOrderId($this->getPaginatedResource(
            'orders',
            '/api/orders',
            [ 'start_date' => $datetime ]
        ));
    }

    // OR11 by order_id
    public function listOrdersById(array $orderIds)
    {
        return $this->mapByOrderId($this->getPaginatedResource(
            'orders',
            '/api/orders',
            [ 'order_ids' => implode(',', $orderIds) ]
        ));
    }

    // OR11 by commercial_id
    public function listOrdersByCommercialId(array $commercialIds)
    {
        return $this->mapByCommercialAndOrderId($this->getPaginatedResource(
            'orders',
            '/api/orders',
            [ 'commercial_ids' => implode(',', $commercialIds) ]
        ));
    }

    // PA11
    public function listPendingDebits()
    {
        return $this->mapByCommercialAndOrderId($this->getPaginatedNestedResource(
            'orders',
            'order',
            '/api/payment/debit'
        ));
        $response = $this->client->request('GET', '/api/payment/debit');
        return $this->getResponseBody($response)['orders']['order'];
    }

    // PA01
    public function validatePayments(array $orders)
    {
        $this->client->request('PUT', '/api/payment/debit', [
            'json' => [ 'orders' => $orders ]
        ]);
    }

    // PA12
    public function listPendingRefunds()
    {
        return $this->mapByOrderId($this->getPaginatedNestedResource(
            'orders',
            'order',
            '/api/payment/refund'
        ));
    }

    // PA02
    public function validateRefunds(array $refunds)
    {
        $this->client->request('PUT', '/api/payment/refund', [
            'json' => [ 'refunds' => $refunds ]
        ]);
    }

    // IV01
    public function listInvoices()
    {
        return $this->mapByInvoiceId($this->getPaginatedResource(
            'invoices',
            '/api/invoices'
        ));
    }

    // IV01 by date
    public function listInvoicesByDate(string $datetime)
    {
        return $this->mapByInvoiceId($this->getPaginatedResource(
            'invoices',
            '/api/invoices',
            [ 'start_date' => $datetime ]
        ));
    }

    // IV01 by shop
    public function listInvoicesByShopId(int $shopId)
    {
        return $this->mapByInvoiceId($this->getPaginatedResource(
            'invoices',
            '/api/invoices',
            [ 'shop' => $shopId ]
        ));
    }

    // S20
    public function fetchShops(?array $shopIds, ?\DateTimeInterface $updatedAfter = null, bool $paginate = true)
    {
        $filters = ['query' => []];
        $filters['query']['domains'] = 'PRODUCT,SERVICE';
        $filters['query']['paginate'] = $paginate ? 'true' : 'false';

        if (null !== $shopIds) {
            $filters['query']['shop_ids'] = implode(',', $shopIds);
        }
        if (null !== $updatedAfter) {
            $filters['query']['updated_since'] = $updatedAfter->format(self::DATE_FORMAT);
        }

        $response = $this->client->request('GET', '/api/shops', $filters);

        return $this->getResponseBody($response)['shops'];
    }

    // S07
    public function patchShops(array $patchedShops)
    {
        $response = $this->client->request('PUT', '/api/shops', [
            'json' => ['shops' => $patchedShops],
        ]);

        return $this->getResponseBody($response)['shop_returns'];
    }

    // parse a date based on the format used by Mirakl
    public static function getDatetimeFromString(string $date): \DateTimeInterface
    {
        $dt = \DateTime::createFromFormat(self::DATE_FORMAT, $date);
        if (!$dt) {
            // Shouldn't happen unless Mirakl changed the date format
            throw new InvalidArgumentException(sprintf(
                self::DATE_FORMAT_INVALID_MESSAGE,
                self::DATE_FORMAT,
                $date
            ));
        }

        return $dt;
    }

    // parse a date based on the format used by Mirakl
    public static function getStringFromDatetime(\DateTimeInterface $date): string
    {
        return $date->format(self::DATE_FORMAT);
    }
}