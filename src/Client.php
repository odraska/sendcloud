<?php

namespace JouwWeb\SendCloud;

use JouwWeb\SendCloud\Model\ParcelItem;
use function GuzzleHttp\default_user_agent;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use JouwWeb\SendCloud\Exception\SendCloudClientException;
use JouwWeb\SendCloud\Exception\SendCloudRequestException;
use JouwWeb\SendCloud\Exception\SendCloudStateException;
use JouwWeb\SendCloud\Exception\SendCloudWebhookException;
use JouwWeb\SendCloud\Model\Address;
use JouwWeb\SendCloud\Model\Parcel;
use JouwWeb\SendCloud\Model\SenderAddress;
use JouwWeb\SendCloud\Model\ShippingMethod;
use JouwWeb\SendCloud\Model\User;
use JouwWeb\SendCloud\Model\WebhookEvent;
use Psr\Http\Message\RequestInterface;

/**
 * Client to perform calls on the Sendcloud API.
 */
class Client
{
    const API_BASE_URL = 'https://panel.sendcloud.sc/api/v2/';

    /** @var \GuzzleHttp\Client */
    protected $guzzleClient;

    /** @var string */
    protected $publicKey;

    /** @var string */
    protected $secretKey;

    /** @var string|null */
    protected $partnerId;

    public function __construct(
        string $publicKey,
        string $secretKey,
        ?string $partnerId = null,
        ?string $apiBaseUrl = null
    ) {
        $this->publicKey = $publicKey;
        $this->secretKey = $secretKey;
        $this->partnerId = $partnerId;

        $clientConfig = [
            'base_uri' => $apiBaseUrl ?: self::API_BASE_URL,
            'timeout' => 15,
            'auth' => [
                $publicKey,
                $secretKey,
            ],
            'headers' => [
                'User-Agent' => 'jouwweb/sendcloud ' . default_user_agent(),
            ],
        ];

        if ($this->partnerId) {
            $clientConfig['headers']['Sendcloud-Partner-Id'] = $this->partnerId;
        }

        $this->guzzleClient = new \GuzzleHttp\Client($clientConfig);
    }

    /**
     * Fetches basic details about the Sendcloud account.
     *
     * @return User
     * @throws SendCloudRequestException
     */
    public function getUser(): User
    {
        try {
            return new User(json_decode((string)$this->guzzleClient->get('user')->getBody(), true)['user']);
        } catch (RequestException $exception) {
            throw $this->parseRequestException($exception, 'An error occurred while fetching the Sendcloud user.');
        }
    }

    /**
     * Fetches available Sendcloud shipping methods.
     *
     * @param int|null $servicePointId If passed, only shipping methods to the service point will be returned.
     * @return ShippingMethod[]
     * @throws SendCloudClientException
     */
    public function getShippingMethods(?int $servicePointId = null): array
    {
        try {
            $queryData = [];

            if ($servicePointId !== null) {
                $queryData['service_point_id'] = $servicePointId;
            }

            $response = $this->guzzleClient->get('shipping_methods', [
                'query' => $queryData,
            ]);
            $shippingMethodsData = json_decode((string)$response->getBody(), true)['shipping_methods'];

            $shippingMethods = array_map(function (array $shippingMethodData) {
                return new ShippingMethod($shippingMethodData);
            }, $shippingMethodsData);

            // Sort by carrier and name
            usort($shippingMethods, function (ShippingMethod $method1, ShippingMethod $method2) {
                if ($method1->getCarrier() !== $method2->getCarrier()) {
                    return strcasecmp($method1->getCarrier(), $method2->getCarrier());
                }

                return strcasecmp($method1->getName(), $method2->getName());
            });

            return $shippingMethods;
        } catch (RequestException $exception) {
            throw $this->parseRequestException(
                $exception,
                'An error occurred while fetching shipping methods from the Sendcloud API.'
            );
        }
    }

    /**
     * Creates a parcel in Sendcloud.
     *
     * @param Address $shippingAddress Address to be shipped to.
     * @param int|null $servicePointId The order will be shipped to the service point if supplied. $shippingAddress is
     * still required as it will be printed on the label.
     * @param string|null $orderNumber
     * @param int|null $weight Weight of the parcel in grams. The default set in Sendcloud will be used if null or zero.
     * @param string|null $customsInvoiceNumber
     * @param int|null One of {@see Parcel::CUSTOMS_SHIPMENT_TYPES}.
     * @param ParcelItem[]|null $items Items contained in the parcel.
     * @return Parcel
     * @throws SendCloudRequestException
     */
    public function createParcel(
        Address $shippingAddress,
        ?int $servicePointId,
        ?string $orderNumber = null,
        ?int $weight = null,
        ?string $customsInvoiceNumber = null,
        ?int $customsShipmentType = null,
        ?array $items = null
    ): Parcel {
        $parcelData = $this->getParcelData(
            null,
            $shippingAddress,
            $servicePointId,
            $orderNumber,
            $weight,
            false,
            null,
            null,
            $customsInvoiceNumber,
            $customsShipmentType,
            $items
        );

        try {
            $response = $this->guzzleClient->post('parcels', [
                'json' => [
                    'parcel' => $parcelData,
                ],
            ]);

            return new Parcel(json_decode((string)$response->getBody(), true)['parcel']);
        } catch (RequestException $exception) {
            throw $this->parseRequestException($exception, 'Could not create parcel in Sendcloud.');
        }
    }

    /**
     * Update details of an existing parcel.
     *
     * @param Parcel|int $parcel
     * @param Address $shippingAddress
     * @return Parcel
     * @throws SendCloudRequestException
     */
    public function updateParcel($parcel, Address $shippingAddress): Parcel
    {
        $parcelData = $this->getParcelData(
            $this->parseParcelArgument($parcel),
            $shippingAddress,
            null,
            null,
            null,
            false,
            null,
            null,
            null,
            null,
            null
        );

        try {
            $response = $this->guzzleClient->put('parcels', [
                'json' => [
                    'parcel' => $parcelData,
                ],
            ]);

            return new Parcel(json_decode((string)$response->getBody(), true)['parcel']);
        } catch (RequestException $exception) {
            throw $this->parseRequestException($exception, 'Could not update parcel in SendCloud.');
        }
    }

    /**
     * Request a label for an existing parcel.
     *
     * @param Parcel|int $parcel
     * @param int $shippingMethodId
     * @param SenderAddress|int|Address|null $senderAddress Passing null will pick Sendcloud's default. An Address will
     * use undocumented behavior that will disable branding personalizations.
     * @return Parcel
     * @throws SendCloudRequestException
     */
    public function createLabel($parcel, int $shippingMethodId, $senderAddress): Parcel
    {
        $parcelData = $this->getParcelData(
            $this->parseParcelArgument($parcel),
            null,
            null,
            null,
            null,
            true,
            $shippingMethodId,
            $senderAddress,
            null,
            null,
            null
        );

        try {
            $response = $this->guzzleClient->put('parcels', [
                'json' => [
                    'parcel' => $parcelData,
                ],
            ]);

            return new Parcel(json_decode((string)$response->getBody(), true)['parcel']);
        } catch (RequestException $exception) {
            throw $this->parseRequestException($exception, 'Could not create parcel with Sendcloud.');
        }
    }

    /**
     * Cancels or deletes a parcel (depending on status). Returns whether the parcel was successfully cancelled.
     *
     * @param Parcel|int $parcel
     * @return bool
     * @throws SendCloudRequestException
     */
    public function cancelParcel($parcel): bool
    {
        try {
            $this->guzzleClient->post(sprintf('parcels/%s/cancel', $this->parseParcelArgument($parcel)));
            return true;
        } catch (RequestException $exception) {
            $statusCode = $exception->hasResponse() ? $exception->getResponse()->getStatusCode() : 0;

            // Handle documented rejections
            if (in_array($statusCode, [400, 410])) {
                return false;
            }

            throw $this->parseRequestException($exception, 'An error occurred while cancelling the parcel.');
        }
    }

    /**
     * Fetches the PDF label for the given parcel. The parcel must already have a label created.
     *
     * @param Parcel|int $parcel
     * @param int $format `Parcel::LABEL_FORMATS`
     * @return string PDF data.
     * @throws SendCloudClientException
     */
    public function getLabelPdf($parcel, int $format): string
    {
        if (!in_array($format, Parcel::LABEL_FORMATS)) {
            throw new \InvalidArgumentException('Invalid label format given.');
        }

        if (is_int($parcel)) {
            $parcel = $this->getParcel($parcel);
        } elseif (!($parcel instanceof Parcel)) {
            throw new \InvalidArgumentException('parcel must be an integer or a Parcel.');
        }

        $labelUrl = $parcel->getLabelUrl($format);

        if (!$labelUrl) {
            throw new SendCloudStateException('SendCloud parcel does not have any labels.');
        }

        try {
            return (string)$this->guzzleClient->get($labelUrl)->getBody();
        } catch (RequestException $exception) {
            throw $this->parseRequestException($exception, 'Could not retrieve label.');
        }
    }

    /**
     * Fetches a single PDF containing labels for all the given parcels. Parcels that do not have a label available will
     * not be contained in the PDF. If only parcels without a label have been requested it will result in an exception.
     *
     * @param Parcel[]|int[] $parcels
     * @param int $format One of `Parcel::LABEL_FORMATS`. The A4 formats will contain up to 4 labels per page.
     * @return string PDF data.
     * @throws SendCloudClientException
     */
    public function getBulkLabelPdf(array $parcels, int $format): string
    {
        $parcelIds = [];
        foreach ($parcels as $parcel) {
            if (is_int($parcel)) {
                $parcelIds[] = $parcel;
            } elseif ($parcel instanceof Parcel) {
                $parcelIds[] = $parcel->getId();
            } else {
                throw new \InvalidArgumentException('Parcels must be integers or Parcel instances.');
            }
        }

        try {
            $response = $this->guzzleClient->post('labels', [
                'json' => [
                    'label' => [
                        'parcels' => $parcelIds,
                    ]
                ],
            ]);
        } catch (RequestException $exception) {
            throw $this->parseRequestException($exception, 'Could not retrieve label information.');
        }

        $labelData = json_decode((string)$response->getBody(), true);
        $labelUrl = Utility::getLabelUrlFromData($labelData, $format);
        if (!$labelUrl) {
            throw new SendCloudStateException('No label URL could be obtained from the response.');
        }

        try {
            return (string)$this->guzzleClient->get($labelUrl)->getBody();
        } catch (RequestException $exception) {
            throw $this->parseRequestException($exception, 'Could not retrieve label PDF data.');
        }
    }

    /**
     * Fetches the sender addresses configured in SendCloud.
     *
     * @return SenderAddress[]
     * @throws SendCloudRequestException
     */
    public function getSenderAddresses(): array
    {
        try {
            $response = $this->guzzleClient->get('user/addresses/sender');
            $senderAddressesData = json_decode((string)$response->getBody(), true)['sender_addresses'];

            return array_map(function (array $senderAddressData) {
                return new SenderAddress($senderAddressData);
            }, $senderAddressesData);
        } catch (RequestException $exception) {
            throw $this->parseRequestException($exception, 'Could not retrieve sender addresses.');
        }
    }

    /**
     * Retrieves current parcel data from SendCloud.
     *
     * @param Parcel|int $parcel
     * @return Parcel
     * @throws SendCloudClientException
     */
    public function getParcel($parcel): Parcel
    {
        try {
            $response = $this->guzzleClient->get('parcels/' . $this->parseParcelArgument($parcel));
            return new Parcel(json_decode((string)$response->getBody(), true)['parcel']);
        } catch (RequestException $exception) {
            throw $this->parseRequestException($exception, 'Could not retrieve parcel.');
        }
    }

    /**
     * Parse a webhook event using the client's secret key. See {@see Utility::parseWebhookRequest()} for specifics.
     *
     * @param RequestInterface $request
     * @return WebhookEvent
     * @throws SendCloudWebhookException
     */
    public function parseWebhookRequest(RequestInterface $request): WebhookEvent
    {
        return Utility::parseWebhookRequest($request, $this->secretKey);
    }

    /**
     * Returns the return portal URL for the given parcel. Returns `null` when no return portal is configured or the
     * parcel is not associated with a brand.
     *
     * @param Parcel|int $parcel
     * @return string|null
     */
    public function getReturnPortalUrl($parcel): ?string
    {
        try {
            $response = $this->guzzleClient->get(sprintf(
                'parcels/%s/return_portal_url',
                $this->parseParcelArgument($parcel)
            ));

            return (string)json_decode($response->getBody(), true)['url'];
        } catch (RequestException $exception) {
            if ($exception->getResponse() && $exception->getResponse()->getStatusCode() === 404) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Returns the given arguments as data in SendCloud parcel format.
     *
     * @param int|null $parcelId
     * @param Address|null $shippingAddress
     * @param string|null $servicePointId
     * @param string|null $orderNumber
     * @param int|null $weight
     * @param bool $requestLabel
     * @param int|null $shippingMethodId Required if requesting a label.
     * @param SenderAddress|int|Address|null $senderAddress Passing null will pick SendCloud's default. An Address will
     * use undocumented behavior that will disable branding personalizations.
     * @param string|null $customsInvoiceNumber
     * @param int|null One of {@see Parcel::CUSTOMS_SHIPMENT_TYPES}.
     * @param ParcelItem[]|null $items
     * @return mixed[]
     */
    protected function getParcelData(
        ?int $parcelId,
        ?Address $shippingAddress,
        ?string $servicePointId,
        ?string $orderNumber,
        ?int $weight,
        bool $requestLabel,
        ?int $shippingMethodId,
        $senderAddress,
        ?string $customsInvoiceNumber,
        ?int $customsShipmentType,
        ?array $items
    ): array {
        $parcelData = [];

        if ($parcelId) {
            $parcelData['id'] = $parcelId;
        }

        if ($shippingAddress) {
            $parcelData = array_merge($parcelData, [
                'name' => $shippingAddress->getName(),
                'company_name' => $shippingAddress->getCompanyName() ?? '',
                'address' => $shippingAddress->getStreet(),
                'address_2' => $shippingAddress->getAddressLine2() ?? '',
                'house_number' => $shippingAddress->getHouseNumber(),
                'city' => $shippingAddress->getCity(),
                'postal_code' => $shippingAddress->getPostalCode(),
                'country' => $shippingAddress->getCountryCode(),
                'email' => $shippingAddress->getEmailAddress(),
                'telephone' => $shippingAddress->getPhoneNumber() ?? '',
                'country_state' => $shippingAddress->getCountryStateCode() ?? '',
            ]);
        }

        if ($servicePointId) {
            $parcelData['to_service_point'] = $servicePointId;
        }

        if ($orderNumber) {
            $parcelData['order_number'] = $orderNumber;
        }

        if ($weight) {
            $parcelData['weight'] = (string)($weight / 1000);
        }

        if ($customsInvoiceNumber) {
            $parcelData['customs_invoice_nr'] = $customsInvoiceNumber;
        }

        if ($customsShipmentType !== null) {
            if (!in_array($customsShipmentType, Parcel::CUSTOMS_SHIPMENT_TYPES)) {
                throw new \InvalidArgumentException(sprintf('Invalid customs shipment type %s.', $customsShipmentType));
            }

            $parcelData['customs_shipment_type'] = $customsShipmentType;
        }

        if ($items) {
            $itemsData = [];

            foreach (array_values($items) as $index => $item) {
                if (!($item instanceof ParcelItem)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Parcel item at index %s is not an instance of ParcelItem.',
                        $index
                    ));
                }

                $itemData = [
                    'description' => $item->getDescription(),
                    'quantity' => $item->getQuantity(),
                    'weight' => (string)($item->getWeight() / 1000),
                    'value' => $item->getValue(),
                ];
                if ($item->getHarmonizedSystemCode()) {
                    $itemData['hs_code'] = $item->getHarmonizedSystemCode();
                }
                if ($item->getOriginCountryCode()) {
                    $itemData['origin_country'] = $item->getOriginCountryCode();
                }
                $itemsData[] = $itemData;
            }

            $parcelData['parcel_items'] = $itemsData;
        }

        // Additional fields are only added when requesting a label
        if ($requestLabel) {
            $parcelData['request_label'] = true;

            // Sender address
            if ($senderAddress instanceof SenderAddress) {
                $senderAddress = $senderAddress->getId();
            }
            if (is_int($senderAddress)) {
                /** @var int $senderAddress */
                $parcelData['sender_address'] = $senderAddress;
            } elseif ($senderAddress instanceof Address) {
                /** @var Address $senderAddress */
                $parcelData = array_merge($parcelData, [
                    'from_name' => $senderAddress->getName(),
                    'from_company_name' => $senderAddress->getCompanyName() ?? '',
                    'from_address_1' => $senderAddress->getStreet(),
                    'from_address_2' => $senderAddress->getAddressLine2() ?? '',
                    'from_house_number' => $senderAddress->getHouseNumber(),
                    'from_city' => $senderAddress->getCity(),
                    'from_postal_code' => $senderAddress->getPostalCode(),
                    'from_country' => $senderAddress->getCountryCode(),
                    'from_telephone' => $senderAddress->getPhoneNumber() ?? '',
                    'from_email' => $senderAddress->getEmailAddress(),
                ]);
            } elseif ($senderAddress !== null) {
                throw new \InvalidArgumentException(
                    '$senderAddressIdOrAddress must be an integer, an Address or null when requesting a label.'
                );
            }

            // Shipping method
            if (!$shippingMethodId) {
                throw new \InvalidArgumentException(
                    '$shippingMethodId must be passed when requesting a label.'
                );
            }

            $parcelData['shipment'] = [
                'id' => $shippingMethodId,
            ];
        }

        return $parcelData;
    }

    protected function parseRequestException(
        RequestException $exception,
        string $defaultMessage
    ): SendCloudRequestException {
        $message = $defaultMessage;
        $code = SendCloudRequestException::CODE_UNKNOWN;

        $responseCode = null;
        $responseMessage = null;
        if ($exception->hasResponse()) {
            $responseData = json_decode((string)$exception->getResponse()->getBody(), true);
            $responseCode = $responseData['error']['code'] ?? null;
            $responseMessage = $responseData['error']['message'] ?? null;
        }

        if ($exception instanceof ConnectException) {
            $message = 'Could not contact SendCloud API.';
            $code = SendCloudRequestException::CODE_CONNECTION_FAILED;
        }

        // Precondition failed, parse response message to determine code of exception
        if ($exception->getCode() === 401) {
            $message = 'Invalid public/secret key combination.';
            $code = SendCloudRequestException::CODE_UNAUTHORIZED;
        } elseif ($exception->getCode() === 412) {
            $message = 'SendCloud account is not fully configured yet.';

            if (stripos($responseMessage, 'no address data') !== false) {
                $code = SendCloudRequestException::CODE_NO_ADDRESS_DATA;
            } elseif (stripos($responseMessage, 'not allowed to announce') !== false) {
                $code = SendCloudRequestException::CODE_NOT_ALLOWED_TO_ANNOUNCE;
            }
        }

        return new SendCloudRequestException($message, $code, $exception, $responseCode, $responseMessage);
    }

    /**
     * @param Parcel|int $parcel
     * @return int
     */
    protected function parseParcelArgument($parcel): int
    {
        if (is_int($parcel)) {
            return $parcel;
        }

        if ($parcel instanceof Parcel) {
            return $parcel->getId();
        }

        throw new \InvalidArgumentException('Parcel argument must be a parcel or parcel ID.');
    }
}
