<?php declare(strict_types=1);

namespace Generate\Controller\Admin;

use Common\Mvc\Controller\Plugin\JSend;
use Common\Stdlib\PsrMessage;
use Generate\Form\QuickSearchForm;
use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Form\ConfirmForm;
use Omeka\Stdlib\ErrorStore;

class IndexController extends AbstractActionController
{
    public function browseAction()
    {
        $params = $this->params()->fromQuery();

        $formSearch = $this->getForm(QuickSearchForm::class);
        $formSearch
            ->setAttribute('action', $this->url()->fromRoute('admin/generated-resource'))
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
        return $this->redirect()->toRoute('admin/id', $params, ['fragment' => 'generated-resource']);
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

    public function addAction()
    {
        /**
         * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource
         *
         * @see \Generate\Mvc\Controller\Plugin\GenerateViaOpenAi $generateViaOpenAi
         */

        // No check for prompt: this is simple text.
        // FIXME The form on resource/show sends a get instead of a post (js).
        $post = $this->params()->fromPost()
            ?: $this->params()->fromQuery();

        if (isset($post['generate'])) {
            $post = $post['generate'];
        }

        $resourceId = (int) ($post['resource_id'] ?? 0);
        if (!$resourceId) {
            $this->messenger()->addError(new PsrMessage(
                'The resource id should be defined to generate a record.' // @translate
            ));
            return $this->redirect()->toRoute('admin/generated-resource', ['action' => 'browse'], true);
        }

        try {
            $resource = $this->api()->read('resources', $resourceId)->getContent();
        } catch (\Exception $e) {
            $this->messenger()->addError(new PsrMessage(
                'The resource to generate is not available.' // @translate
            ));
            return $this->redirect()->toRoute('admin/generated-resource', ['action' => 'browse'], true);
        }

        // Generating may be expensive, so there is a specific check for roles.
        $generateRoles = $this->settings()->get('generate_roles');
        $user = $this->identity();
        if (!$user || !in_array($user->getRole(), $generateRoles)) {
            $this->messenger()->addError(new PsrMessage(
                'The user is not allowed to generate a record.' // @translate
            ));
            return $this->redirect()->toRoute('admin/generated-resource', ['action' => 'browse'], true);
        }

        // Check for specific prompts.
        $promptSystem = $post['generate_prompt_system'] ?? null;
        $promptUser = $post['generate_prompt_user'] ?? null;

        $generatedResource = $this->generateViaOpenAi($resource, [
            'prompt_system' => $promptSystem,
            'prompt_user' => $promptUser,
        ]);

        if ($generatedResource) {
            $this->messenger()->addSuccess(new PsrMessage(
                'The resource was generated successfully.' // @translate
            ));
        }

        $params = [];
        $params['controller'] = $resource->getControllerName();
        $params['action'] = 'show';
        $params['id'] = $resource->id();
        return $this->redirect()->toRoute('admin/id', $params, ['fragment' => 'generated-resource']);
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
        return $this->redirect()->toRoute('admin/generated-resource', ['action' => 'browse'], true);
    }

    public function batchDeleteAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/generated-resource');
        }

        $resourceIds = $this->params()->fromPost('resource_ids', []);
        if (!$resourceIds) {
            $this->messenger()->addError('You must select at least one generated resource to batch delete.'); // @translate
            return $this->redirect()->toRoute('admin/generated-resource');
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
        return $this->redirect()->toRoute('admin/generated-resource');
    }

    public function batchDeleteAllAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/generated-resource');
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
        return $this->redirect()->toRoute('admin/generated-resource');
    }

    public function batchProcessAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/generated-resource');
        }

        // The action is the key used for submit.
        $post = $this->params()->fromPost();

        $actions = [
            'read_selected',
            'unread_selected',
            'validate_selected',
        ];
        $action = key(array_intersect_key($post, array_flip($actions)));
        if (!$action) {
            $this->messenger()->addError('You must select a valid action to batch process.'); // @translate
            return $this->redirect()->toRoute('admin/generated-resource');
        }

        $resourceIds = $post['resource_ids'] ?? [];
        if (!$resourceIds) {
            $this->messenger()->addError('You must select at least one generated resource to batch process.'); // @translate
            return $this->redirect()->toRoute('admin/generated-resource');
        }

        // Process validation in background in all cases.
        if ($action === 'validate_selected') {
            return $this->processValidate(['id' => $resourceIds]);
        }

        /** @var \Omeka\Mvc\Controller\Plugin\Api $api */
        $api = $this->api();
        $response = $api->batchUpdate('generated_resources', $resourceIds, ['o:reviewed' => $action === 'read_selected'], ['isPartial' => true, 'continueOnError' => true]);
        if ($response) {
            $this->messenger()->addSuccess('Generated resources successfully updated'); // @translate
        } else {
            $this->messenger()->addError('An error occurred during update'); // @translate
        }

        return $this->redirect()->toRoute('admin/generated-resource');
    }

    public function batchProcessAllAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/generated-resource');
        }

        // The action is the key used for submit.
        $post = $this->params()->fromPost();

        $actions = [
            'read_all',
            'unread_all',
            'validate_all',
        ];
        $action = key(array_intersect_key($post, array_flip($actions)));
        if (!$action) {
            $this->messenger()->addError('You must select a valid action to batch process.'); // @translate
            return $this->redirect()->toRoute('admin/generated-resource');
        }

        // Derive the query, removing limiting and sorting params.
        $query = json_decode($this->params()->fromPost('query', []), true);
        unset($query['submit'], $query['page'], $query['per_page'], $query['limit'],
        $query['offset'], $query['sort_by'], $query['sort_order']);

        // Process validation in background in all cases.
        if ($action === 'validate_all') {
            return $this->processValidate($query);
        }

        $job = $this->jobDispatcher()->dispatch(\Omeka\Job\BatchUpdate::class, [
            'resource' => 'generated_resources',
            'query' => $query,
            'data' => ['o:reviewed' => $action === 'read_all'],
        ]);

        $urlPlugin = $this->url();
        $message = new PsrMessage(
            'Processing update of resources in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
            [
                'link_job' => sprintf(
                    '<a href="%s">',
                    htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
                    ),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
                'link_log' => class_exists('Log\Module', false)
                    ? sprintf('<a href="%1$s">', $urlPlugin->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]))
                    : sprintf('<a href="%1$s" target="_blank">', $urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()])),
            ]
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);

        return $this->redirect()->toRoute('admin/generated-resource');
    }

    protected function processValidate(array $query)
    {
        $job = $this->jobDispatcher()->dispatch(\Generate\Job\BatchValidate::class, [
            'query' => $query,
        ]);

        $urlPlugin = $this->url();
        $message = new PsrMessage(
            'Processing validation of resources in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
            [
                'link_job' => sprintf(
                    '<a href="%s">',
                    htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
                    ),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
                'link_log' => class_exists('Log\Module', false)
                    ? sprintf('<a href="%1$s">', $urlPlugin->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]))
                    : sprintf('<a href="%1$s" target="_blank">', $urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()])),
            ]
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);

        return $this->redirect()->toRoute('admin/generated-resource');
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
        $resource = $this->validateRecordOrCreateOrUpdate($generatedResource, $resourceData, $errorStore, false, false, false);
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
        $resource = $this->validateRecordOrCreateOrUpdate($generatedResource, $resourceData, $errorStore, true, false, false);

        if ($errorStore->hasErrors()) {
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
            // All generated resources are patches, since the resource exists.
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
        $resource = $this->validateRecordOrCreateOrUpdate($generatedResource, $resourceData, $errorStore, false, false, false);
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
}
