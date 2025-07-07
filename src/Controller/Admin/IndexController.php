<?php declare(strict_types=1);

namespace AiGenerator\Controller\Admin;

use AiGenerator\Form\QuickSearchForm;
use Common\Mvc\Controller\Plugin\JSend;
use Common\Stdlib\PsrMessage;
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
            ->setAttribute('action', $this->url()->fromRoute('admin/ai-record'))
            ->setAttribute('id', 'ai-record-search');

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

        $this->browse()->setDefaults('ai_records');

        $response = $this->api()->search('ai_records', $params);
        $this->paginator($response->getTotalResults());

        /** @var \Omeka\Form\ConfirmForm $formDeleteSelected */
        $formDeleteSelected = $this->getForm(ConfirmForm::class);
        $formDeleteSelected
            ->setAttribute('id', 'confirm-delete-selected')
            ->setAttribute('action', $this->url()->fromRoute('admin/ai-record/default', ['action' => 'batch-delete'], true))
            ->setButtonLabel('Confirm Delete'); // @translate

        /** @var \Omeka\Form\ConfirmForm $formDeleteAll */
        $formDeleteAll = $this->getForm(ConfirmForm::class);
        $formDeleteAll
            ->setAttribute('id', 'confirm-delete-all')
            ->setAttribute('action', $this->url()->fromRoute('admin/ai-record/default', ['action' => 'batch-delete-all'], true))
            ->setButtonLabel('Confirm Delete'); // @translate
        $formDeleteAll
            ->get('submit')->setAttribute('disabled', true);

        $aiRecords = $response->getContent();

        return new ViewModel([
            'aiRecords' => $aiRecords,
            'resources' => $aiRecords,
            'formSearch' => $formSearch,
            'formDeleteSelected' => $formDeleteSelected,
            'formDeleteAll' => $formDeleteAll,
        ]);
    }

    public function showAction()
    {
        $params = $this->params()->fromRoute();
        $response = $this->api()->read('ai_records', $this->params('id'));
        $aiRecord = $response->getContent();
        $relatedResource = $aiRecord->resource();
        if (!$relatedResource) {
            $message = new PsrMessage('This ai record is a new resource or has no more resource.'); // @translate
            $this->messenger()->addError($message);
            $params['action'] = 'browse';
            return $this->forward()->dispatch('AiGenerator\Controller\Admin\Index', $params);
        }

        $params = [];
        $params['controller'] = $relatedResource->getControllerName();
        $params['action'] = 'show';
        $params['id'] = $relatedResource->id();
        return $this->redirect()->toRoute('admin/id', $params, ['fragment' => 'ai-record']);
    }

    public function showDetailsAction()
    {
        $linkTitle = (bool) $this->params()->fromQuery('link-title', true);
        $response = $this->api()->read('ai_records', $this->params('id'));
        $aiRecord = $response->getContent();

        $view = new ViewModel([
            'linkTitle' => $linkTitle,
            'resource' => $aiRecord,
            'values' => json_encode([]),
        ]);
        return $view
            ->setTemplate('ai-generator/admin/index/show-details')
            ->setTerminal(true);
    }

    public function addAction()
    {
        /**
         * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource
         *
         * @see \AiGenerator\Mvc\Controller\Plugin\GenerateViaOpenAi $generateViaOpenAi
         */

        // FIXME The form on resource/show sends a get instead of a post (js).
        $post = $this->params()->fromPost()
            ?: $this->params()->fromQuery();

        // TODO Check input with form validation.
        $generate = $post['ai_generator'] ?? $post;

        $resourceId = (int) ($generate['resource_id'] ?? 0);
        if (!$resourceId) {
            $this->messenger()->addError(new PsrMessage(
                'The resource id should be defined to generate a record.' // @translate
            ));
            return $this->redirect()->toRoute('admin/ai-record');
        }

        try {
            $resource = $this->api()->read('resources', $resourceId)->getContent();
        } catch (\Exception $e) {
            $this->messenger()->addError(new PsrMessage(
                'The resource to generate is not available.' // @translate
            ));
            return $this->redirect()->toRoute('admin/ai-record');
        }

        // Generating may be expensive, so there is a specific check for roles.
        $generateRoles = $this->settings()->get('aigenerator_roles', []);
        $user = $this->identity();
        if (!$user || !in_array($user->getRole(), $generateRoles)) {
            $this->messenger()->addError(new PsrMessage(
                'The user is not allowed to generate a record.' // @translate
            ));
            return $this->redirect()->toRoute('admin/ai-record');
        }

        $args = [
            'model' => $generate['model'] ?? null,
            'validate' => !empty($generate['validate']),
            'max_tokens' => $generate['max_tokens'] ?? null,
            'derivative' => $generate['derivative'] ?? null,
            'prompt_system' => $generate['prompt_system'] ?? null,
            'prompt_user' => $generate['prompt_user'] ?? null,
        ];

        $aiRecord = $this->generateViaOpenAi($resource, $args);

        if ($aiRecord) {
            $this->messenger()->addSuccess(new PsrMessage(
                'The resource was generated successfully.' // @translate
            ));
        }

        $params = [];
        $params['controller'] = $resource->getControllerName();
        $params['action'] = 'show';
        $params['id'] = $resource->id();
        return $this->redirect()->toRoute('admin/id', $params, ['fragment' => 'ai-record']);
    }

    public function deleteConfirmAction()
    {
        $linkTitle = (bool) $this->params()->fromQuery('link-title', true);
        $response = $this->api()->read('ai_records', $this->params('id'));
        $aiRecord = $response->getContent();

        $view = new ViewModel([
            'aiRecord' => $aiRecord,
            'resource' => $aiRecord,
            'resourceLabel' => 'AI record', // @translate
            'partialPath' => 'ai-generator/admin/index/show-details',
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
                $response = $this->api($form)->delete('ai_records', $this->params('id'));
                if ($response) {
                    $this->messenger()->addSuccess('AI record successfully deleted'); // @translate
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }
        return $this->redirect()->toRoute('admin/ai-record');
    }

    public function batchDeleteAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/ai-record');
        }

        $resourceIds = $this->params()->fromPost('resource_ids', []);
        if (!$resourceIds) {
            $this->messenger()->addError('You must select at least one ai record to batch delete.'); // @translate
            return $this->redirect()->toRoute('admin/ai-record');
        }

        $form = $this->getForm(ConfirmForm::class);
        $form->setData($this->getRequest()->getPost());
        if ($form->isValid()) {
            $response = $this->api($form)->batchDelete('ai_records', $resourceIds, [], ['continueOnError' => true]);
            if ($response) {
                $this->messenger()->addSuccess('AI records successfully deleted'); // @translate
            }
        } else {
            $this->messenger()->addFormErrors($form);
        }
        return $this->redirect()->toRoute('admin/ai-record');
    }

    public function batchDeleteAllAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/ai-record');
        }

        // Derive the query, removing limiting and sorting params.
        $query = json_decode($this->params()->fromPost('query', []), true);
        unset($query['submit'], $query['page'], $query['per_page'], $query['limit'],
           $query['offset'], $query['sort_by'], $query['sort_order']);

        $form = $this->getForm(ConfirmForm::class);
        $form->setData($this->getRequest()->getPost());
        if ($form->isValid()) {
            $this->jobDispatcher()->dispatch(\Omeka\Job\BatchDelete::class, [
                'resource' => 'ai_records',
                'query' => $query,
            ]);
            $this->messenger()->addSuccess('Deleting ai records. This may take a while.'); // @translate
        } else {
            $this->messenger()->addFormErrors($form);
        }
        return $this->redirect()->toRoute('admin/ai-record');
    }

    public function batchProcessAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/ai-record');
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
            return $this->redirect()->toRoute('admin/ai-record');
        }

        $resourceIds = $post['resource_ids'] ?? [];
        if (!$resourceIds) {
            $this->messenger()->addError('You must select at least one ai record to batch process.'); // @translate
            return $this->redirect()->toRoute('admin/ai-record');
        }

        // Process validation in background in all cases.
        if ($action === 'validate_selected') {
            return $this->processValidate(['id' => $resourceIds]);
        }

        /** @var \Omeka\Mvc\Controller\Plugin\Api $api */
        $api = $this->api();
        $response = $api->batchUpdate('ai_records', $resourceIds, ['o:reviewed' => $action === 'read_selected'], ['isPartial' => true, 'continueOnError' => true]);
        if ($response) {
            $this->messenger()->addSuccess('AI records successfully updated'); // @translate
        } else {
            $this->messenger()->addError('An error occurred during update'); // @translate
        }

        return $this->redirect()->toRoute('admin/ai-record');
    }

    public function batchProcessAllAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/ai-record');
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
            return $this->redirect()->toRoute('admin/ai-record');
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
            'resource' => 'ai_records',
            'query' => $query,
            'data' => ['o:reviewed' => $action === 'read_all'],
        ]);

        $urlPlugin = $this->url();
        $message = new PsrMessage(
            'Processing update of ai records in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
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

        return $this->redirect()->toRoute('admin/ai-record');
    }

    protected function processValidate(array $query)
    {
        $job = $this->jobDispatcher()->dispatch(\AiGenerator\Job\BatchValidate::class, [
            'query' => $query,
        ]);

        $urlPlugin = $this->url();
        $message = new PsrMessage(
            'Processing validation of ai records in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
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

        return $this->redirect()->toRoute('admin/ai-record');
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

        /** @var \AiGenerator\Api\Representation\AiRecordRepresentation $aiRecord */
        try {
            $aiRecord = $this->api()->read('ai_records', $id)->getContent();
        } catch (\Exception $e) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // Only a resource already added can have a status reviewed.
        $relatedResource = $aiRecord ? $aiRecord->resource() : null;
        if (!$relatedResource) {
            return $this->jSend(JSend::SUCCESS, [
                // Status is updated, so inverted.
                'ai_record' => [
                    'status' => 'unreviewed',
                    'statusLabel' => $this->translate('Unreviewed'), // @translate
                ],
            ]);
        }

        // Only people who can edit the resource can update the status.
        if ($relatedResource && !$relatedResource->userIsAllowed('update')) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Unauthorized access.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        $isReviewed = $aiRecord->isReviewed();

        $data = [];
        $data['o:reviewed'] = !$isReviewed;
        $response = $this->api()
            ->update('ai_records', $id, $data, [], ['isPartial' => true]);
        if (!$response) {
            return $this->jSend(JSend::ERROR, null, $this->translate(
                'An internal error occurred.' // @translate
            ));
        }

        return $this->jSend(JSend::SUCCESS, [
            // Status is updated, so inverted.
            'ai_record' => [
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

        /** @var \AiGenerator\Api\Representation\AiRecordRepresentation $aiRecord */
        try {
            $aiRecord = $this->api()->read('ai_records', $id)->getContent();
        } catch (\Exception $e) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // If there is a resource, it can't be created.
        // This is always the case with AiGenerator, unlike Contribute.
        $relatedResource = $aiRecord->resource();
        if ($relatedResource) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Resource already exists.' // @translate
            ), HttpResponse::STATUS_CODE_400);
        }

        // Only people who can create resource can validate.
        $acl = $aiRecord->getServiceLocator()->get('Omeka\Acl');
        if (!$acl->userIsAllowed(\Omeka\Api\Adapter\ItemAdapter::class, 'create')) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Unauthorized access.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        $resourceData = $aiRecord->proposalToResourceData();
        if (!$resourceData) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'AI record is not valid: check template.' // @translate
            ));
        }

        // Validate and create the resource.
        $errorStore = new ErrorStore();
        $resource = $this->validateRecordOrCreateOrUpdate($aiRecord, $resourceData, $errorStore, false, false, false);
        if ($errorStore->hasErrors()) {
            // Keep similar messages different to simplify debug.
            return $this->jSend(JSend::FAIL, $errorStore->getErrors() ?: null, $this->translate(
                'AI record cannot be created: some values are not valid.' // @translate
            ));
        }
        if (!$resource) {
            return $this->jSend(JSend::ERROR, null, $this->translate(
                'An internal error occurred.' // @translate
            ));
        }

        return $this->jSend(JSend::SUCCESS, [
            'ai_record' => $aiRecord,
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

        /** @var \AiGenerator\Api\Representation\AiRecordRepresentation $aiRecord */
        try {
            $aiRecord = $this->api()->read('ai_records', $id)->getContent();
        } catch (\Exception $e) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // If there is no resource, create it as a whole.
        $relatedResource = $aiRecord->resource();

        // Only people who can edit the resource can validate.
        if (($relatedResource && !$relatedResource->userIsAllowed('update'))
            || (!$relatedResource && !$aiRecord->getServiceLocator()->get('Omeka\Acl')->userIsAllowed('Omeka\Api\Adapter\ItemAdapter', 'create'))
        ) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Unauthorized access.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        $resourceData = $aiRecord->proposalToResourceData();
        if (!$resourceData) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'AI record is not valid.' // @translate
            ));
        }

        // Validate and update the resource.
        // The status "reviewed" is set to true, because a validation requires
        // a review.

        $errorStore = new ErrorStore();
        $resource = $this->validateRecordOrCreateOrUpdate($aiRecord, $resourceData, $errorStore, true, false, false);

        if ($errorStore->hasErrors()) {
            // Keep similar messages different to simplify debug.
            return $this->jSend(JSend::FAIL, $errorStore->getErrors() ?: null, $this->translate(
                'AI record is not valid: check its values.' // @translate
            ));
        }

        if (!$resource) {
            return $this->jSend(JSend::ERROR, null, $this->translate(
                'An internal error occurred.' // @translate
            ));
        }

        return $this->jSend(JSend::SUCCESS, [
            // Status is updated, so inverted.
            'ai_record' => [
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

        /** @var \AiGenerator\Api\Representation\AiRecordRepresentation $aiRecord */
        try {
            $aiRecord = $this->api()->read('ai_records', $id)->getContent();
        } catch (\Exception $e) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // A resource is required to update it.
        $relatedResource = $aiRecord->resource();
        if (!$relatedResource) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // Only people who can edit the resource can validate.
        if (!$relatedResource->userIsAllowed('update')) {
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

        $resourceData = $aiRecord->proposalToResourceData($term, $key);
        if (!$resourceData) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'AI record is not valid.' // @translate
            ));
        }

        // The status "reviewed" is not modified, because a partial validation
        // does not imply a full review.
        $errorStore = new ErrorStore();
        $resource = $this->validateRecordOrCreateOrUpdate($aiRecord, $resourceData, $errorStore, false, false, false);
        if ($errorStore->hasErrors()) {
            // Keep similar messages different to simplify debug.
            return $this->jSend(JSend::FAIL, $errorStore->getErrors() ?: null, $this->translate(
                'AI record is not valid: check values.' // @translate
            ));
        }
        if (!$resource) {
            return $this->jSend(JSend::ERROR, null, $this->translate(
                'An internal error occurred.' // @translate
            ));
        }

        return $this->jSend(JSend::SUCCESS, [
            // Status is updated, so inverted.
            'ai_record' => [
                'status' => 'validated-value',
                'statusLabel' => $this->translate('Validated value'), // @translate
            ],
        ]);
    }
}
