<?php

declare(strict_types=1);

namespace AiGenerator\Mvc\Controller\Plugin;

use AiGenerator\Api\Representation\AiRecordRepresentation;
use Common\Stdlib\PsrMessage;
use Doctrine\ORM\EntityManager;
use Laminas\Authentication\AuthenticationServiceInterface;
use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Mvc\Controller\Plugin\Api as ApiController;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Permissions\Acl;
use Omeka\Stdlib\ErrorStore;

class ValidateRecordOrCreateOrUpdate extends AbstractPlugin
{
    /**
     * @var \Omeka\Permissions\Acl $acl
     */
    protected $acl;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\Api
     */
    protected $apiController;

    /**
     * @var \Laminas\Authentication\AuthenticationServiceInterface
     */
    protected $authentication;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Laminas\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\Messenger
     */
    protected $messenger;

    public function __construct(
        Acl $acl,
        ApiManager $api,
        ApiController $apiController,
        AuthenticationServiceInterface $authentication,
        EntityManager $entityManager,
        LoggerInterface $logger,
        Messenger $messenger,
    ) {
        $this->acl = $acl;
        $this->api = $api;
        $this->apiController = $apiController;
        $this->authentication = $authentication;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->messenger = $messenger;
    }

    /**
     * Generate resource metadata via OpenAI.
     *
     * @see https://packagist.org/packages/chatgptcom/chatgpt-php
     *
     * @var array $options
     * - prompt_system (string|false): specific prompt for the system (session).
     *   The configured prompt in settings is used by default, unless false is
     *   passed.
     * - prompt_user (string): specific prompt.
     */
    public function __invoke(
        AiRecordRepresentation $aiRecord,
        array $resourceData,
        ErrorStore $errorStore,
        bool $reviewed = false,
        bool $validateOnly = false,
        bool $useMessenger = false
    ): ?AbstractResourceEntityRepresentation {
        $relatedResource = $aiRecord->resource();

        // Nothing to update or create.
        if (!$resourceData) {
            return $relatedResource;
        }

        // Prepare the api to throw a validation exception with error store.
        /** @var \Omeka\Mvc\Controller\Plugin\Api $api */
        $api = $this->apiController->__invoke(null, true);

        // Files are managed through media (already stored).
        // @see proposalToResourceData()
        unset($resourceData['file']);

        // TODO This is a new generation, so a new item for now.
        $resourceName = $relatedResource ? $relatedResource->resourceName() : 'items';

        // Validate only: the simplest way is to skip flushing.
        // Nevertheless, a simple generator has no right to create a resource.
        // So skip rights before and remove skip after.
        // But some other modules can persist it inadvertently (?)
        // So use api, and add an event to add an error to the error store and
        // check if it is the only one.
        // TODO Fix the modules that flush too much early.
        // TODO Improve the api manager with method or option "validateOnly"?
        // TODO Add a method to get the error store from the api without using exception.
        $isAllowed = null;
        if ($validateOnly) {
            // Flush before and clear after to avoid possible issues.
            $this->entityManager->flush();

            $classes = [
                'items' => 'Item',
                'item_sets' => 'ItemSet',
                'media' => 'Media',
            ];
            $class = $classes[$resourceName] ?? 'Item';
            $entityClass = 'Omeka\Entity\\' . $class;
            $action = $relatedResource ? 'update' : 'create';
            $isAllowed = $acl->userIsAllowed($entityClass, $action);
            if (!$isAllowed) {
                $user = $this->authentication->getIdentity();
                $classes = [
                    \Omeka\Entity\Item::class,
                    \Omeka\Entity\Media::class,
                    \Omeka\Entity\ItemSet::class,
                    \Omeka\Api\Adapter\ItemAdapter::class,
                    \Omeka\Api\Adapter\MediaAdapter::class,
                    \Omeka\Api\Adapter\ItemSetAdapter::class,
                ];
                $acl->allow($user ? $user->getRole() : null, $classes, [$action, 'change-owner']);
            }
            $apiOptions = ['flushEntityManager' => false, 'validateOnly' => true, 'isGeneration' => true];
        } else {
            $apiOptions = [];
        }

        try {
            if ($relatedResource) {
                // During an update of items, keep existing media in any cases.
                // TODO Move this check in proposalToResourceData(). Do it for item sets and sites too.
                // @link https://gitlab.com/Daniel-KM/Omeka-S-module-Contribute/-/issues/3
                if ($resourceName === 'items') {
                    unset(
                        $resourceData['o:media'],
                        $resourceData['o:primary_media'],
                        $resourceData['o:item_set'],
                        $resourceData['o:site']
                    );
                }
                $apiOptions['isPartial'] = true;
                $response = $api
                    ->update($resourceName, $relatedResource->id(), $resourceData, [], $apiOptions);
            } else {
                // The validator is not the generator.
                // The validator will be added automatically for anonymous.
                $owner = $aiRecord->owner() ?: null;
                $resourceData['o:owner'] = $owner ? ['o:id' => $owner->id()] : null;
                $resourceData['o:is_public'] = false;
                $response = $api
                    ->create($resourceName, $resourceData, [], $apiOptions);
            }
        } catch (\Omeka\Api\Exception\ValidationException $e) {
            $this->entityManager->clear();
            $exceptionErrorStore = $e->getErrorStore();
            // Check if there is only one error in case of validation only.
            if ($validateOnly) {
                $errors = $exceptionErrorStore->getErrors();
                // Because validateOnly is the last error and added only when
                // there is no other one, it should be alone when no issue.
                if (!count($errors)
                    || (count($errors) === 1 && !empty($errors['validateOnly']))
                ) {
                    $errors = [];
                } else {
                    $errorStore->mergeErrors($exceptionErrorStore);
                    $errors = $errorStore->getErrors();
                }
            } else {
                $errorStore->mergeErrors($exceptionErrorStore);
                $errors = $errorStore->getErrors();
            }
            if ($useMessenger && $errors) {
                // Nested forms with medias create multiple levels of messages.
                // The module fixes core, but may be absent.
                foreach ($errorStore->getErrors() as $messages) {
                    foreach ($messages as $message) {
                        if (is_array($message)) {
                            foreach ($message as $msg) {
                                if (is_array($msg)) {
                                    foreach ($msg as $mg) {
                                        $this->messenger->addError($mg);
                                    }
                                } else {
                                    $this->messenger->addError($msg);
                                }
                            }
                        } else {
                            $this->messenger->addError($message);
                        }
                    }
                }
            }
            if ($isAllowed === false) {
                $acl->deny($user ? $user->getRole() : null, $classes, [$action, 'change-owner']);
            }
            return null;
        } catch (\Exception $e) {
            $this->entityManager->clear();
            $message = new PsrMessage(
                'Unable to store the resource of the ai record: {message}', // @translate
                ['message' => $e->getMessage()]
            );
            $this->logger()->err($message);
            if ($useMessenger) {
                $this->messenger()->addError($message);
            } else {
                $errorStore->addError('store', $message);
            }
            if ($isAllowed === false) {
                $this->acl->deny($user ? $user->getRole() : null, $classes, [$action, 'change-owner']);
            }
            return null;
        }

        if ($isAllowed === false) {
            $this->acl->deny($user ? $user->getRole() : null, $classes, [$action, 'change-owner']);
        }

        // Normally, not possible here.
        if ($validateOnly) {
            $this->entityManager->clear();
            return null;
        }

        // The exception is thrown in the api, there is always a response.
        $relatedResource = $response->getContent();

        $data = [];
        $data['o:resource'] = $validateOnly || !$relatedResource ? null : ['o:id' => $relatedResource->id()];
        $data['o:reviewed'] = $reviewed;
        $response = $this->api
            ->update('ai_records', $aiRecord->id(), $data, [], ['isPartial' => true]);

        return $relatedResource;
    }
}
