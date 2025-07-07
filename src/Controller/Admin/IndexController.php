<?php declare(strict_types=1);

namespace Generate\Controller\Admin;

use Common\Mvc\Controller\Plugin\JSend;
use Common\Stdlib\PsrMessage;
use Doctrine\ORM\EntityManager;
use Generate\Api\Representation\GeneratedResourceRepresentation;
use Generate\Form\QuickSearchForm;
use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Form\ConfirmForm;
use Omeka\Stdlib\ErrorStore;

class IndexController extends AbstractActionController
{
    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function browseAction()
    {
        $params = $this->params()->fromQuery();

        $formSearch = $this->getForm(QuickSearchForm::class);
        $formSearch
            ->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'browse'], true))
            ->setAttribute('id', 'generated-resource-search');

        // Fix form radio for empty value and form select.
        $data = $params;
        if (isset($data['reviewed'])) {
            if ($data['reviewed'] === '0') {
                $data['reviewed'] = '00';
            } elseif ($params['reviewed'] === '00') {
                $params['reviewed'] = '0';
            }
        }
        if (isset($data['resource_template_id']) && is_array($data['resource_template_id'])) {
            $data['resource_template_id'] = empty($data['resource_template_id']) ? '' : reset($data['resource_template_id']);
            $params['resource_template_id'] = $data['resource_template_id'];
        }
        if (isset($data['owner_id']) && is_array($data['owner_id'])) {
            $data['owner_id'] = empty($data['owner_id']) ? '' : reset($data['owner_id']);
            $params['owner_id'] = $data['owner_id'];
        }

        // Don't check validity: this is a search form.
        $formSearch->setData($data);

        $this->setBrowseDefaults('created', 'desc');
        if (!isset($params['sort_by'])) {
            $params['sort_by'] = 'created';
            $params['sort_order'] = 'desc';
        }

        $this->browse()->setDefaults('generated_resources');

        $response = $this->api()->search('generated_resources', $params);
        $this->paginator($response->getTotalResults());

        /** @var \Omeka\Form\ConfirmForm $formDeleteSelected */
        $formDeleteSelected = $this->getForm(ConfirmForm::class);
        $formDeleteSelected
            ->setAttribute('id', 'confirm-delete-selected')
            ->setAttribute('action', $this->url()->fromRoute('admin/generated-resource/default', ['action' => 'batch-delete'], true))
            ->setButtonLabel('Confirm Delete'); // @translate

        /** @var \Omeka\Form\ConfirmForm $formDeleteAll */
        $formDeleteAll = $this->getForm(ConfirmForm::class);
        $formDeleteAll
            ->setAttribute('id', 'confirm-delete-all')
            ->setAttribute('action', $this->url()->fromRoute('admin/generated-resource/default', ['action' => 'batch-delete-all'], true))
            ->setButtonLabel('Confirm Delete'); // @translate
        $formDeleteAll
            ->get('submit')->setAttribute('disabled', true);

        $generatedResources = $response->getContent();

        return new ViewModel([
            'generatedResources' => $generatedResources,
            'resources' => $generatedResources,
            'formSearch' => $formSearch,
            'formDeleteSelected' => $formDeleteSelected,
            'formDeleteAll' => $formDeleteAll,
        ]);
    }

    public function showAction()
    {
        $params = $this->params()->fromRoute();
        $response = $this->api()->read('generated_resources', $this->params('id'));
        $generatedResource = $response->getContent();
        $res = $generatedResource->resource();
        if (!$res) {
            $message = new PsrMessage('This generated resource is a new resource or has no more resource.'); // @translate
            $this->messenger()->addError($message);
            $params['action'] = 'browse';
            return $this->forward()->dispatch('Generate\Controller\Admin\Index', $params);
        }

        $params = [];
        $params['controller'] = $res->getControllerName();
        $params['action'] = 'show';
        $params['id'] = $res->id();
        $url = $this->url()->fromRoute('admin/id', $params, ['fragment' => 'generated-resource']);
        return $this->redirect()->toUrl($url);
    }

    public function showDetailsAction()
    {
        $linkTitle = (bool) $this->params()->fromQuery('link-title', true);
        $response = $this->api()->read('generated_resources', $this->params('id'));
        $generatedResource = $response->getContent();

        $view = new ViewModel([
            'linkTitle' => $linkTitle,
            'resource' => $generatedResource,
            'values' => json_encode([]),
        ]);
        return $view
            ->setTemplate('generate/admin/index/show-details')
            ->setTerminal(true);
    }

    public function deleteConfirmAction()
    {
        $linkTitle = (bool) $this->params()->fromQuery('link-title', true);
        $response = $this->api()->read('generated_resources', $this->params('id'));
        $generatedResource = $response->getContent();

        $view = new ViewModel([
            'generatedResource' => $generatedResource,
            'resource' => $generatedResource,
            'resourceLabel' => 'generated resource', // @translate
            'partialPath' => 'generate/admin/index/show-details',
            'linkTitle' => $linkTitle,
            'values' => json_encode([]),
        ]);
        return $view
            ->setTerminal(true)
            ->setTemplate('common/delete-confirm-details');
    }

    public function deleteAction()
    {
        if ($this->getRequest()->isPost()) {
            $form = $this->getForm(ConfirmForm::class);
            $form->setData($this->getRequest()->getPost());
            if ($form->isValid()) {
                $response = $this->api($form)->delete('generated_resources', $this->params('id'));
                if ($response) {
                    $this->messenger()->addSuccess('Generated resource successfully deleted'); // @translate
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }
        return $this->redirect()->toRoute(
            'admin/generated-resource',
            ['action' => 'browse'],
            true
        );
    }

    public function batchDeleteAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(null, ['action' => 'browse'], true);
        }

        $resourceIds = $this->params()->fromPost('resource_ids', []);
        if (!$resourceIds) {
            $this->messenger()->addError('You must select at least one generated resource to batch delete.'); // @translate
            return $this->redirect()->toRoute(null, ['action' => 'browse'], true);
        }

        $form = $this->getForm(ConfirmForm::class);
        $form->setData($this->getRequest()->getPost());
        if ($form->isValid()) {
            $response = $this->api($form)->batchDelete('generated_resources', $resourceIds, [], ['continueOnError' => true]);
            if ($response) {
                $this->messenger()->addSuccess('Generated resources successfully deleted'); // @translate
            }
        } else {
            $this->messenger()->addFormErrors($form);
        }
        return $this->redirect()->toRoute(null, ['action' => 'browse'], true);
    }

    public function batchDeleteAllAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(null, ['action' => 'browse'], true);
        }

        // Derive the query, removing limiting and sorting params.
        $query = json_decode($this->params()->fromPost('query', []), true);
        unset($query['submit'], $query['page'], $query['per_page'], $query['limit'],
            $query['offset'], $query['sort_by'], $query['sort_order']);

        $form = $this->getForm(ConfirmForm::class);
        $form->setData($this->getRequest()->getPost());
        if ($form->isValid()) {
            $this->jobDispatcher()->dispatch(\Omeka\Job\BatchDelete::class, [
                'resource' => 'generated_resources',
                'query' => $query,
            ]);
            $this->messenger()->addSuccess('Deleting generated resources. This may take a while.'); // @translate
        } else {
            $this->messenger()->addFormErrors($form);
        }
        return $this->redirect()->toRoute(null, ['action' => 'browse'], true);
    }

    /* Ajax */

    public function toggleStatusAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Method not allowed.' // @translate
            ), HttpResponse::STATUS_CODE_405);
        }

        $id = $this->params('id');

        /** @var \Generate\Api\Representation\GeneratedResourceRepresentation $generatedResource */
        try {
            $generatedResource = $this->api()->read('generated_resources', $id)->getContent();
        } catch (\Exception $e) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // Only a resource already added can have a status reviewed.
        $resource = $generatedResource ? $generatedResource->resource() : null;
        if (!$resource) {
            return $this->jSend(JSend::SUCCESS, [
                // Status is updated, so inverted.
                'generated_resource' => [
                    'status' => 'unreviewed',
                    'statusLabel' => $this->translate('Unreviewed'), // @translate
                ],
            ]);
        }

        // Only people who can edit the resource can update the status.
        if ($resource && !$resource->userIsAllowed('update')) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Unauthorized access.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        $isReviewed = $generatedResource->isReviewed();

        $data = [];
        $data['o:reviewed'] = !$isReviewed;
        $response = $this->api()
            ->update('generated_resources', $id, $data, [], ['isPartial' => true]);
        if (!$response) {
            return $this->jSend(JSend::ERROR, null, $this->translate(
                'An internal error occurred.' // @translate
            ));
        }

        return $this->jSend(JSend::SUCCESS, [
            // Status is updated, so inverted.
            'generated_resource' => [
                'status' => $isReviewed ? 'unreviewed' : 'reviewed',
                'statusLabel' => $isReviewed ? $this->translate('Unreviewed') : $this->translate('Reviewed'), // @translate
            ],
        ]);
    }

    public function createResourceAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Method not allowed.' // @translate
            ), HttpResponse::STATUS_CODE_405);
        }

        $id = $this->params('id');

        /** @var \Generate\Api\Representation\GeneratedResourceRepresentation $generatedResource */
        try {
            $generatedResource = $this->api()->read('generated_resources', $id)->getContent();
        } catch (\Exception $e) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // If there is a resource, it can't be created.
        // This is always the case with Generate, unlike Contribution.
        $generatedResourceResource = $generatedResource->resource();
        if ($generatedResourceResource) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // Only people who can create resource can validate.
        $acl = $generatedResource->getServiceLocator()->get('Omeka\Acl');
        if (!$acl->userIsAllowed(\Omeka\Api\Adapter\ItemAdapter::class, 'create')) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Unauthorized access.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        $resourceData = $generatedResource->proposalToResourceData();
        if (!$resourceData) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Generated resource is not valid: check template.' // @translate
            ));
        }

        // Validate and create the resource.
        $errorStore = new ErrorStore();
        $resource = $this->validateOrCreateOrUpdate($generatedResource, $resourceData, $errorStore, false, false, false);
        if ($errorStore->hasErrors()) {
            // Keep similar messages different to simplify debug.
            return $this->jSend(JSend::FAIL, $errorStore->getErrors() ?: null, $this->translate(
                'Generated resource cannot be created: some values are not valid.' // @translate
            ));
        }
        if (!$resource) {
            return $this->jSend(JSend::ERROR, null, $this->translate(
                'An internal error occurred.' // @translate
            ));
        }

        return $this->jSend(JSend::SUCCESS, [
            'generated_resource' => $generatedResource,
            'is_new' => true,
            'url' => $resource->adminUrl(),
        ]);
    }

    public function validateAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Method not allowed.' // @translate
            ), HttpResponse::STATUS_CODE_405);
        }

        $id = $this->params('id');

        /** @var \Generate\Api\Representation\GeneratedResourceRepresentation $generatedResource */
        try {
            $generatedResource = $this->api()->read('generated_resources', $id)->getContent();
        } catch (\Exception $e) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // If there is no resource, create it as a whole.
        $generatedResourceResource = $generatedResource->resource();

        // Only people who can edit the resource can validate.
        if (($generatedResourceResource && !$generatedResourceResource->userIsAllowed('update'))
            || (!$generatedResourceResource && !$generatedResource->getServiceLocator()->get('Omeka\Acl')->userIsAllowed('Omeka\Api\Adapter\ItemAdapter', 'create'))
        ) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Unauthorized access.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        $resourceData = $generatedResource->proposalToResourceData();
        if (!$resourceData) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Generated resource is not valid.' // @translate
            ));
        }

        // Validate and update the resource.
        // The status "reviewed" is set to true, because a validation requires
        // a review.
        $errorStore = new ErrorStore();
        $resource = $this->validateOrCreateOrUpdate($generatedResource, $resourceData, $errorStore, true, false, false);
        if ($errorStore->hasErrors()) {
            // Keep similar messages different to simplify debug.
            // Keep similar messages different to simplify debug.
            return $this->jSend(JSend::FAIL, $errorStore->getErrors() ?: null, $this->translate(
                'Generated resource is not valid: check its values.' // @translate
            ));
        }
        if (!$resource) {
            return $this->jSend(JSend::ERROR, null, $this->translate(
                'An internal error occurred.' // @translate
            ));
        }

        return $this->jSend(JSend::SUCCESS, [
            // Status is updated, so inverted.
            'generated_resource' => [
                'status' => 'validated',
                'statusLabel' => $this->translate('Validated'), // @translate
                'reviewed' => [
                    'status' => 'reviewed',
                    'statusLabel' => $this->translate('Reviewed'), // @translate
                ],
            ],
            // All generated resources are patches, since the resource exists..
            'is_new' => false,
            'url' => $resource->adminUrl(),
        ]);
    }

    public function validateValueAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Method not allowed.' // @translate
            ), HttpResponse::STATUS_CODE_405);
        }

        $id = $this->params('id');

        /** @var \Generate\Api\Representation\GeneratedResourceRepresentation $generatedResource */
        try {
            $generatedResource = $this->api()->read('generated_resources', $id)->getContent();
        } catch (\Exception $e) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // A resource is required to update it.
        $generatedResourceResource = $generatedResource->resource();
        if (!$generatedResourceResource) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // Only people who can edit the resource can validate.
        if (!$generatedResourceResource->userIsAllowed('update')) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Unauthorized access.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        $term = $this->params()->fromQuery('term');
        $key = $this->params()->fromQuery('key');
        if (!$term || !is_numeric($key)) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Missing term or key.' // @translate
            ));
        }

        $key = (int) $key;

        $resourceData = $generatedResource->proposalToResourceData($term, $key);
        if (!$resourceData) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Generated resource is not valid.' // @translate
            ));
        }

        // The status "reviewed" is not modified, because a partial validation
        // does not imply a full review.
        $errorStore = new ErrorStore();
        $resource = $this->validateOrCreateOrUpdate($generatedResource, $resourceData, $errorStore, false, false, false);
        if ($errorStore->hasErrors()) {
            // Keep similar messages different to simplify debug.
            return $this->jSend(JSend::FAIL, $errorStore->getErrors() ?: null, $this->translate(
                'Generated resource is not valid: check values.' // @translate
            ));
        }
        if (!$resource) {
            return $this->jSend(JSend::ERROR, null, $this->translate(
                'An internal error occurred.' // @translate
            ));
        }

        return $this->jSend(JSend::SUCCESS, [
            // Status is updated, so inverted.
            'generated_resource' => [
                'status' => 'validated-value',
                'statusLabel' => $this->translate('Validated value'), // @translate
            ],
        ]);
    }

    /**
     * Create or update a resource from data.
     */
    protected function validateOrCreateOrUpdate(
        GeneratedResourceRepresentation $generatedResource,
        array $resourceData,
        ErrorStore $errorStore,
        bool $reviewed = false,
        bool $validateOnly = false,
        bool $useMessenger = false
    ): ?AbstractResourceEntityRepresentation {
        $generatedResourceResource = $generatedResource->resource();

        // Nothing to update or create.
        if (!$resourceData) {
            return $generatedResourceResource;
        }

        // Prepare the api to throw a validation exception with error store.
        /** @var \Omeka\Mvc\Controller\Plugin\Api $api */
        $api = $this->api(null, true);

        // Files are managed through media (already stored).
        // @see proposalToResourceData()
        unset($resourceData['file']);

        // TODO This is a new generation, so a new item for now.
        $resourceName = $generatedResourceResource ? $generatedResourceResource->resourceName() : 'items';

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

            /** * @var \Omeka\Permissions\Acl $acl */
            $acl = $generatedResource->getServiceLocator()->get('Omeka\Acl');
            $classes = [
                'items' => 'Item',
                'item_sets' => 'ItemSet',
                'media' => 'Media',
            ];
            $class = $classes[$resourceName] ?? 'Item';
            $entityClass = 'Omeka\Entity\\' . $class;
            $action = $generatedResourceResource ? 'update' : 'create';
            $isAllowed = $acl->userIsAllowed($entityClass, $action);
            if (!$isAllowed) {
                $user = $this->identity();
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
            if ($generatedResourceResource) {
                // During an update of items, keep existing media in any cases.
                // TODO Move this check in proposalToResourceData(). Do it for item sets and sites too.
                // @link https://gitlab.com/Daniel-KM/Omeka-S-module-Generate/-/issues/3
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
                    ->update($resourceName, $generatedResourceResource->id(), $resourceData, [], $apiOptions);
            } else {
                // The validator is not the generator.
                // The validator will be added automatically for anonymous.
                $owner = $generatedResource->owner() ?: null;
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
                /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
                $messenger = $this->messenger();
                // Nested forms with medias create multiple levels of messages.
                // The module fixes core, but may be absent.
                foreach ($errorStore->getErrors() as $messages) {
                    foreach ($messages as $message) {
                        if (is_array($message)) {
                            foreach ($message as $msg) {
                                if (is_array($msg)) {
                                    foreach ($msg as $mg) {
                                        $messenger->addError($this->translate($mg));
                                    }
                                } else {
                                    $messenger->addError($this->translate($msg));
                                }
                            }
                        } else {
                            $messenger->addError($this->translate($message));
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
                'Unable to store the resource of the generated resource: {message}', // @translate
                ['message' => $e->getMessage()]
            );
            $this->logger()->err($message);
            if ($useMessenger) {
                $this->messenger()->addError($message);
            } else {
                $errorStore->addError('store', $message);
            }
            if ($isAllowed === false) {
                $acl->deny($user ? $user->getRole() : null, $classes, [$action, 'change-owner']);
            }
            return null;
        }

        if ($isAllowed === false) {
            $acl->deny($user ? $user->getRole() : null, $classes, [$action, 'change-owner']);
        }

        // Normally, not possible here.
        if ($validateOnly) {
            $this->entityManager->clear();
            return null;
        }

        // The exception is thrown in the api, there is always a response.
        $generatedResourceResource = $response->getContent();

        $data = [];
        $data['o:resource'] = $validateOnly || !$generatedResourceResource ? null : ['o:id' => $generatedResourceResource->id()];
        $data['o:reviewed'] = $reviewed;
        $response = $this->api()
            ->update('generated_resources', $generatedResource->id(), $data, [], ['isPartial' => true]);

        return $generatedResourceResource;
    }
}
