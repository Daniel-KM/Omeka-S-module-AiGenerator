<?php

namespace DoctrineProxies\__CG__\AiGenerator\Entity;


/**
 * DO NOT EDIT THIS FILE - IT WAS CREATED BY DOCTRINE'S PROXY GENERATOR
 */
class AiRecord extends \AiGenerator\Entity\AiRecord implements \Doctrine\ORM\Proxy\Proxy
{
    /**
     * @var \Closure the callback responsible for loading properties in the proxy object. This callback is called with
     *      three parameters, being respectively the proxy object to be initialized, the method that triggered the
     *      initialization process and an array of ordered parameters that were passed to that method.
     *
     * @see \Doctrine\Common\Proxy\Proxy::__setInitializer
     */
    public $__initializer__;

    /**
     * @var \Closure the callback responsible of loading properties that need to be copied in the cloned object
     *
     * @see \Doctrine\Common\Proxy\Proxy::__setCloner
     */
    public $__cloner__;

    /**
     * @var boolean flag indicating if this object was already initialized
     *
     * @see \Doctrine\Persistence\Proxy::__isInitialized
     */
    public $__isInitialized__ = false;

    /**
     * @var array<string, null> properties to be lazy loaded, indexed by property name
     */
    public static $lazyPropertiesNames = array (
);

    /**
     * @var array<string, mixed> default values of properties to be lazy loaded, with keys being the property names
     *
     * @see \Doctrine\Common\Proxy\Proxy::__getLazyProperties
     */
    public static $lazyPropertiesDefaults = array (
);



    public function __construct(?\Closure $initializer = null, ?\Closure $cloner = null)
    {

        $this->__initializer__ = $initializer;
        $this->__cloner__      = $cloner;
    }







    /**
     * 
     * @return array
     */
    public function __sleep()
    {
        if ($this->__isInitialized__) {
            return ['__isInitialized__', 'id', 'resource', 'owner', 'model', 'responseid', 'tokensInput', 'tokensOutput', 'reviewed', 'proposal', 'created', 'modified'];
        }

        return ['__isInitialized__', 'id', 'resource', 'owner', 'model', 'responseid', 'tokensInput', 'tokensOutput', 'reviewed', 'proposal', 'created', 'modified'];
    }

    /**
     * 
     */
    public function __wakeup()
    {
        if ( ! $this->__isInitialized__) {
            $this->__initializer__ = function (AiRecord $proxy) {
                $proxy->__setInitializer(null);
                $proxy->__setCloner(null);

                $existingProperties = get_object_vars($proxy);

                foreach ($proxy::$lazyPropertiesDefaults as $property => $defaultValue) {
                    if ( ! array_key_exists($property, $existingProperties)) {
                        $proxy->$property = $defaultValue;
                    }
                }
            };

        }
    }

    /**
     * 
     */
    public function __clone()
    {
        $this->__cloner__ && $this->__cloner__->__invoke($this, '__clone', []);
    }

    /**
     * Forces initialization of the proxy
     */
    public function __load(): void
    {
        $this->__initializer__ && $this->__initializer__->__invoke($this, '__load', []);
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     */
    public function __isInitialized(): bool
    {
        return $this->__isInitialized__;
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     */
    public function __setInitialized($initialized): void
    {
        $this->__isInitialized__ = $initialized;
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     */
    public function __setInitializer(\Closure $initializer = null): void
    {
        $this->__initializer__ = $initializer;
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     */
    public function __getInitializer(): ?\Closure
    {
        return $this->__initializer__;
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     */
    public function __setCloner(\Closure $cloner = null): void
    {
        $this->__cloner__ = $cloner;
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific cloning logic
     */
    public function __getCloner(): ?\Closure
    {
        return $this->__cloner__;
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     * @deprecated no longer in use - generated code now relies on internal components rather than generated public API
     * @static
     */
    public function __getLazyProperties(): array
    {
        return self::$lazyPropertiesDefaults;
    }

    
    /**
     * {@inheritDoc}
     */
    public function getId()
    {
        if ($this->__isInitialized__ === false) {
            return (int)  parent::getId();
        }


        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getId', []);

        return parent::getId();
    }

    /**
     * {@inheritDoc}
     */
    public function setResource(?\Omeka\Entity\Resource $resource): \AiGenerator\Entity\AiRecord
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'setResource', [$resource]);

        return parent::setResource($resource);
    }

    /**
     * {@inheritDoc}
     */
    public function getResource(): ?\Omeka\Entity\Resource
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getResource', []);

        return parent::getResource();
    }

    /**
     * {@inheritDoc}
     */
    public function setOwner(?\Omeka\Entity\User $owner): \AiGenerator\Entity\AiRecord
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'setOwner', [$owner]);

        return parent::setOwner($owner);
    }

    /**
     * {@inheritDoc}
     */
    public function getOwner(): ?\Omeka\Entity\User
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getOwner', []);

        return parent::getOwner();
    }

    /**
     * {@inheritDoc}
     */
    public function setModel(?string $model): \AiGenerator\Entity\AiRecord
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'setModel', [$model]);

        return parent::setModel($model);
    }

    /**
     * {@inheritDoc}
     */
    public function getModel(): string
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getModel', []);

        return parent::getModel();
    }

    /**
     * {@inheritDoc}
     */
    public function setResponseid(?string $responseid): \AiGenerator\Entity\AiRecord
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'setResponseid', [$responseid]);

        return parent::setResponseid($responseid);
    }

    /**
     * {@inheritDoc}
     */
    public function getResponseid(): string
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getResponseid', []);

        return parent::getResponseid();
    }

    /**
     * {@inheritDoc}
     */
    public function setTokensInput(?int $tokensInput): \AiGenerator\Entity\AiRecord
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'setTokensInput', [$tokensInput]);

        return parent::setTokensInput($tokensInput);
    }

    /**
     * {@inheritDoc}
     */
    public function getTokensInput(): int
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getTokensInput', []);

        return parent::getTokensInput();
    }

    /**
     * {@inheritDoc}
     */
    public function setTokensOutput(?int $tokensOutput): \AiGenerator\Entity\AiRecord
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'setTokensOutput', [$tokensOutput]);

        return parent::setTokensOutput($tokensOutput);
    }

    /**
     * {@inheritDoc}
     */
    public function getTokensOutput(): int
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getTokensOutput', []);

        return parent::getTokensOutput();
    }

    /**
     * {@inheritDoc}
     */
    public function setReviewed($reviewed): \AiGenerator\Entity\AiRecord
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'setReviewed', [$reviewed]);

        return parent::setReviewed($reviewed);
    }

    /**
     * {@inheritDoc}
     */
    public function getReviewed(): bool
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getReviewed', []);

        return parent::getReviewed();
    }

    /**
     * {@inheritDoc}
     */
    public function setProposal(array $proposal): \AiGenerator\Entity\AiRecord
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'setProposal', [$proposal]);

        return parent::setProposal($proposal);
    }

    /**
     * {@inheritDoc}
     */
    public function getProposal(): array
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getProposal', []);

        return parent::getProposal();
    }

    /**
     * {@inheritDoc}
     */
    public function setCreated(\DateTime $dateTime): \AiGenerator\Entity\AiRecord
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'setCreated', [$dateTime]);

        return parent::setCreated($dateTime);
    }

    /**
     * {@inheritDoc}
     */
    public function getCreated(): \DateTime
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getCreated', []);

        return parent::getCreated();
    }

    /**
     * {@inheritDoc}
     */
    public function setModified(?\DateTime $dateTime): \AiGenerator\Entity\AiRecord
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'setModified', [$dateTime]);

        return parent::setModified($dateTime);
    }

    /**
     * {@inheritDoc}
     */
    public function getModified(): ?\DateTime
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getModified', []);

        return parent::getModified();
    }

    /**
     * {@inheritDoc}
     */
    public function getResourceId()
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getResourceId', []);

        return parent::getResourceId();
    }

}
