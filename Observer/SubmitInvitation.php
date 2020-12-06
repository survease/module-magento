<?php

declare(strict_types=1);

namespace Survease\Survey\Observer;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class SubmitInvitation implements ObserverInterface
{
    private $logger;

    private $config;

    public function __construct(LoggerInterface $logger, ScopeConfigInterface $config)
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    public function execute(Observer $observer)
    {
        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();

        if ($this->shouldSend($order) && $billingAddress = $order->getBillingAddress()) {
            $this->doRequest($order, $billingAddress);
        }
    }

    private function doRequest(OrderInterface $order, OrderAddressInterface $address)
    {
        $apiToken = $this->config->getValue('survey/authorization/api_token');
        $surveyId = $this->config->getValue('survey/general/survey_id');
        $defer = $this->config->getValue('survey/general/defer') ?: 0;

        if (!$apiToken || !$surveyId) {
            $this->logger->warning('Survease: Api Token and Survey Id missing for invitations dispatch');
            return;
        }

        $handler = new HandlerStack(new CurlHandler());
        $handler->push(Middleware::retry(static function ($retries, $request, ResponseInterface $response = null) {
            // Only retry 3 times if response is a server error
            return $retries < 3 && $response && str_starts_with((string)$response->getStatusCode(), '5');
        }));
        // todo: inject through di
        $promise = (new Client([
            'base_uri' => 'https://app.survease.io/api/v1/',
            'handler' => $handler,
        ]))->requestAsync('POST', 'survey/' . $surveyId . '/invitations', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiToken,
            ],
            'json' => [
                [
                    'firstName' => $address->getFirstname(),
                    'lastName' => $address->getLastname(),
                    'email' => $order->getCustomerEmail(),
                    'realDate' => \Safe\strtotime($order->getCreatedAt()),
                    'dispatchAt' => date_create()->add(new \DateInterval('P' . ((int)$defer) . 'D'))->getTimestamp(),
                ],
            ],
        ]);

        $promise->then(function () {
            $this->logger->info('Survease: Invitation submitted');
        }, function (RequestException $e) {
            $this->logger->error("Survease: Invitations submit error", ['exception' => $e]);
        });
    }

    private function shouldSend(OrderInterface $order = null): bool
    {
        return $order && $order->getState() === Order::STATE_COMPLETE;
    }
}
