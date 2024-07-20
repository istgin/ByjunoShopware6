<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Log;

use DateTimeInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class CembraLogEntity extends Entity
{
    use EntityIdTrait;

    /**
     * @var string
     */
    protected $requestId;

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    /**
     * @var string
     */
    protected $requestType;

    public function getRequestType(): string
    {
        return $this->requestType;
    }

    public function setRequestType(string $requestType): void
    {
        $this->requestType = $requestType;
    }

    /**
     * @var string
     */
    protected $firstname;

    public function getFirstname(): string
    {
        return $this->firstname;
    }

    public function setFirstname(string $firstname): void
    {
        $this->firstname = $firstname;
    }

    /**
     * @var string
     */
    protected $lastname;

    public function getLastname(): string
    {
        return $this->lastname;
    }

    public function setLastname(string $lastname): void
    {
        $this->lastname = $lastname;
    }

    /**
     * @var string
     */
    protected $town;

    public function getTown(): string
    {
        return $this->town;
    }

    public function setTown(string $town): void
    {
        $this->town = $town;
    }

    /**
     * @var string
     */
    protected $postcode;

    public function getPostcode(): string
    {
        return $this->postcode;
    }

    public function setPostcode(string $postcode): void
    {
        $this->postcode = $postcode;
    }

    /**
     * @var string
     */
    protected $street;

    public function getStreet(): string
    {
        return $this->street;
    }

    public function setStreet(string $street): void
    {
        $this->street = $street;
    }

    /**
     * @var string
     */
    protected $country;

    public function getCountry(): string
    {
        return $this->country;
    }

    public function setCountry(string $country): void
    {
        $this->country = $country;
    }

    /**
     * @var string
     */
    protected $ip;

    public function getIP(): string
    {
        return $this->ip;
    }

    public function setIP(string $ip): void
    {
        $this->ip = $ip;
    }

    /**
     * @var string
     */
    protected $cembraStatus;

    public function getCembraStatus(): string
    {
        return $this->cembraStatus;
    }

    public function setCembraStatus(string $cembraStatus): void
    {
        $this->cembraStatus = $cembraStatus;
    }

    /**
     * @var string
     */
    protected $orderId;

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function setOrderId(string $orderId): void
    {
        $this->orderId = $orderId;
    }

    /**
     * @var string
     */
    protected $transactionId;

    public function getTransactionId(): string
    {
        return $this->transactionId;
    }

    public function setTransactionId(string $transactionId): void
    {
        $this->transactionId = $transactionId;
    }

    /**
     * @ORM\Column(name="request", type="text", precision=0, scale=0, nullable=false, unique=false)
     */
    protected $request;

    public function getRequest(): string
    {
        return $this->request;
    }

    public function setRequest(string $request): void
    {
        $this->request = $request;
    }

    /**
     * @ORM\Column(name="response", type="text", precision=0, scale=0, nullable=false, unique=false)
     */
    protected $response;

    public function getResponse(): string
    {
        return $this->response;
    }

    public function setResponse(string $response): void
    {
        $this->response = $response;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }
}