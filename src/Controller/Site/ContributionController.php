<?php declare(strict_types=1);

namespace Generate\Controller\Site;

use Common\Stdlib\PsrMessage;
use Generate\Api\Representation\GenerationRepresentation;
use Generate\Controller\GenerationTrait;
use Generate\Form\GenerateForm;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
// TODO Use the admin resource form, but there are some differences in features (validation by field, possibility to update the item before validate correction, anonymous, fields is more end user friendly and enough in most of the cases), themes and security issues, so not sure it is simpler.
// use Omeka\Form\ResourceForm;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\File\TempFileFactory;
use Omeka\File\Uploader;
use Omeka\Stdlib\ErrorStore;

class GenerationController extends AbstractActionController
{
    use GenerationTrait;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Omeka\File\TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var \Omeka\File\Uploader
     */
    protected $uploader;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var string
     */
    protected $string;

    public function __construct(
        EntityManager $entityManager,
        TempFileFactory $tempFileFactory,
        Uploader $uploader,
        ?string $basePath,
        array $config
    ) {
        $this->entityManager = $entityManager;
        $this->tempFileFactory = $tempFileFactory;
        $this->uploader = $uploader;
        $this->basePath = $basePath;
        $this->config = $config;
    }

    public function showAction()
    {
        $resourceType = $this->params('resource');
        $resourceId = $this->params('id');

        $resourceTypeMap = [
            'generation' => 'Generate\Controller\Site\Generation',
            'item' => 'Omeka\Controller\Site\Item',
            'media' => 'Omeka\Controller\Site\Media',
            'item-set' => 'Omeka\Controller\Site\ItemSet',
        ];
        // Useless, because managed by route, but the config may be overridden.
        if (!isset($resourceTypeMap[$resourceType])) {
            return $this->notFoundAction();
        }
        $site = $this->currentSite();

        if ($resourceType !== 'generation') {
            // TODO Use forward dispatch to avoid the redirect, but clear event context and params, else items events are not triggered.
            // return $this->forward()->dispatch($resourceTypeMap[$resourceType], [
            return $this->redirect()->toRoute('site/resource-id', [
                'site-slug' => $this->currentSite()->slug(),
                'controller' => $resourceType,
                'action' => 'show',
                'id' => $resourceId,
            ]);
        }

        // Rights are automatically checked.
        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        $generation = $this->api()->read('generations', ['id' => $resourceId])->getContent();

        $space = $this->params('space', 'default');

        $view = new ViewModel([
            'site' => $site,
            'resource' => $generation->resource(),
            'generation' => $generation,
            'space' => $space,
        ]);
        return $view
            ->setTemplate($space === 'guest'
                ? 'guest/site/guest/generation-show'
                : 'generate/site/generation/show'
            );
    }

    /**
     * The action "view" is a proxy to "show", that cannot be used because it is
     * used by the resources.
     * @deprecated Use show. Will be remove in a future release.
     */
    public function viewAction()
    {
        $params = $this->params()->fromRoute();
        $params['action'] = 'show';
        return $this->forward()->dispatch('Generate\Controller\Site\Generation', $params);
    }

    public function addAction()
    {
        $site = $this->currentSite();
        $resourceType = $this->params('resource');

        $resourceTypeMap = [
            'generation' => 'items',
            'item' => 'items',
            'media' => 'media',
            'item-set' => 'item_sets',
        ];
        // Useless, because managed by route, but the config may be overridden.
        if (!isset($resourceTypeMap[$resourceType])) {
            return $this->notFoundAction();
        }

        if ($resourceType === 'generation') {
            $resourceType = 'item';
        }
        // TODO Use the resource name to store the generation (always items here for now).
        $resourceName = $resourceTypeMap[$resourceType];

        $user = $this->identity();

        $canGenerate = $this->viewHelpers()->get('canGenerate');
        $canEditWithoutToken = $canGenerate();

        // TODO Allow to use a token to add a resource.
        // $token = $this->checkToken($resource);
        $token = null;
        // Check rights to edit without token.
        if (!$token && !$canEditWithoutToken) {
            return $this->viewError403();
        }

        // Prepare the resource template. Use the first if not queryied.

        /** @var \Generate\Mvc\Controller\Plugin\GenerativeData $generativeData */
        $generativeData = $this->getPluginManager()->get('generativeData');
        $allowedResourceTemplates = $this->settings()->get('generate_templates', []);
        $templates = [];
        $templateLabels = [];
        // Remove non-generative templates.
        if ($allowedResourceTemplates) {
            foreach ($this->api()->search('resource_templates', ['id' => $allowedResourceTemplates])->getContent() as $template) {
                $generative = $generativeData($template);
                if ($generative->isGenerative()) {
                    $templates[$template->id()] = $template;
                    $templateLabels[$template->id()] = $template->label();
                }
            }
        }

        $params = $this->params();

        // When there is an id, it means to show template readonly, else forward
        // to edit.
        $resourceId = $params->fromRoute('id') ?? null;
        if ($resourceId) {
            // Edition is always the right generation or resource.
            $resourceTypeMap['generation'] = 'generations';
            $resourceName = $resourceTypeMap[$this->params('resource')];
            // Rights are automatically checked.
            /** @var \Generate\Api\Representation\GenerationRepresentation|\Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
            $resource = $this->api()->read($resourceName, ['id' => $resourceId])->getContent();
            $resourceTemplate = $resource->resourceTemplate();
            if ($resourceTemplate) {
                $generative = clone $generativeData($resourceTemplate);
                $resourceTemplate = $generative->template();
            }
            $template = $resourceTemplate ? $resourceTemplate->id() : -1;
        } else {
            // A template is required to generate: set by query or previous form.
            $template = $params->fromQuery('template') ?: $params->fromPost('template');
            /** @var \Generate\Mvc\Controller\Plugin\GenerativeData $generative */
            if ($template) {
                $generative = clone $generativeData($template);
                $resourceTemplate = $generative->template();
            }
        }

        $space = $this->params('space', 'default');

        if (!count($templates) || ($template && !$resourceTemplate)) {
            $this->logger()->err('A template is required to add a resource. Ask the administrator for more information.'); // @translate
            $view = new ViewModel([
                'site' => $site,
                'user' => $user,
                'form' => null,
                'resourceTemplate' => null,
                'generation' => null,
                'resource' => null,
                'fields' => [],
                'fieldsByMedia' => [],
                'fieldsMediaBase' => [],
                'action' => 'add',
                'mode' => 'read',
                'space' => $space,
            ]);
            return $view
                ->setTemplate($space === 'guest'
                    ? 'guest/site/guest/generation-edit'
                    : 'generate/site/generation/edit'
                );
        }

        if (!$template) {
            if (count($templates) === 1) {
                $resourceTemplate = reset($templates);
                $generative = clone $generativeData($template);
            } else {
                $resourceTemplate = null;
            }
        }

        $mode = $resourceId || $params->fromPost('mode', 'write') === 'read' ? 'read' : 'write';

        /** @var \Generate\Form\GenerateForm $form */
        $formOptions = [
            'templates' => $templateLabels,
            'display_select_template' => $mode === 'read' || $resourceId || !$resourceTemplate,
        ];
        $form = $this->getForm(GenerateForm::class, $formOptions)
            // Use setOptions() + init(), not getForm(), because of the bug in csrf / getForm().
            // ->setOptions($formOptions)
            ->setAttribute('enctype', 'multipart/form-data')
            ->setAttribute('id', 'edit-resource');

        if ($mode === 'read') {
            $form->setDisplayTemplateSelect(true);
            $form->setAttribute('class', 'readonly');
            $form->get('template')->setAttribute('readonly', 'readonly');
            $form->get('submit')->setAttribute('disabled', 'disabled');
            $form->get('mode')->setValue('read');
        }
        if ($resourceTemplate) {
            $form->get('template')->setValue($resourceTemplate->id());
        }

        // First step: select a template if not set. Else mode is read only.
        // The read-only allows to use multi-steps form.
        if (!$resourceTemplate || $mode === 'read') {
            $view = new ViewModel([
                'site' => $site,
                'user' => $user,
                'form' => $form,
                'resourceTemplate' => $resourceTemplate,
                'generation' => $resourceId && $resource && $resource instanceof GenerationRepresentation ? $resource : null,
                'resource' => $resourceId && $resource && $resource instanceof AbstractResourceEntityRepresentation ? $resource : null,
                'fields' => [],
                'fieldsByMedia' => [],
                'fieldsMediaBase' => [],
                'action' => 'add',
                'mode' => $resourceId ? 'read' : $mode,
                'space' => $space,
            ]);
            return $view
                ->setTemplate($space === 'guest'
                    ? 'guest/site/guest/generation-edit'
                    : 'generate/site/generation/edit'
                );
        }

        // In all other cases (second step), the mode is write, else the called
        // method would be edit.
        if ($resourceId) {
            $params = $this->params()->fromRoute();
            $params['action'] = 'edit';
            return $this->forward()->dispatch('Generate\Controller\Site\Generation', $params);
        }

        $step = $params->fromPost('step');

        // Second step: fill the template and create a generation, even partial.
        $hasError = false;
        if ($this->getRequest()->isPost() && $step !== 'template') {
            $post = $params->fromPost();
            // The template cannot be changed once set.
            $post['template'] = $resourceTemplate->id();
            $form->setData($post);
            // TODO There is no check currently (html form), except the csrf.
            if ($form->isValid()) {
                // TODO There is no validation by the form, except csrf, since elements are added through views. So use form (but includes non-updatable values, etc.).
                // $data = $form->getData();
                $data = array_diff_key($post, ['csrf' => null, 'edit-resource-submit' => null]);
                $data = $this->checkAndIncludeFileData($data);
                // To simplify process, a direct submission is made with a
                // create then an update.
                $allowUpdate = $this->settings()->get('generate_allow_update');
                $isDirectSubmission = $allowUpdate === 'no';
                if (empty($data['has_error'])) {
                    $proposal = $this->prepareProposal($data);
                    if ($proposal) {
                        // When there is a resource, it isn’t updated, but the
                        // proposition of generation is saved for moderation.
                        $data = [
                            'o:resource' => null,
                            'o:owner' => $user ? ['o:id' => $user->getId()] : null,
                            'o-module-generate:token' => $token ? ['o:id' => $token->id()] : null,
                            'o:email' => $token ? $token->email() : ($user ? $user->getEmail() : null),
                            'o-module-generate:patch' => false,
                            'o-module-generate:submitted' => false,
                            'o-module-generate:reviewed' => false,
                            'o-module-generate:proposal' => $proposal,
                        ];
                        $response = $this->api($form)->create('generations', $data);
                        if ($response) {
                            /** @var \Generate\Api\Representation\GenerationRepresentation $generation $content */
                            $generation = $response->getContent();
                            // $this->prepareGenerationEmail($response->getContent(), 'prepare');
                            $eventManager = $this->getEventManager();
                            $eventManager->trigger('generate.submit', $this, [
                                'generation' => $generation,
                                'resource' => null,
                                'data' => $data,
                            ]);
                            // For a direct submission, process via the normal
                            // submission.
                            // Note that the submission may be invalid for now.
                            // TODO Process a direct submission without full validation.
                            if ($isDirectSubmission) {
                                $params = $this->params()->fromRoute();
                                $params['controller'] = 'Generate\Controller\Site\Generation';
                                $params['__CONTROLLER__'] = 'generation';
                                $params['action'] = 'submit';
                                $params['resource'] = 'generation';
                                $params['id'] = $generation->id();
                                $params['space'] = $space;
                                return $this->forward()->dispatch('Generate\Controller\Site\Generation', $params);
                            }
                            $message = $this->settings()->get('generate_message_add');
                            if ($message) {
                                $this->messenger()->addSuccess($message);
                            } else {
                                $this->messenger()->addSuccess('Generation successfully saved!'); // @translate
                                $this->messenger()->addWarning('Review it before its submission.'); // @translate
                            }
                            return $generation->resource()
                                ? $this->redirect()->toUrl($generation->resource()->siteUrl())
                                : $this->redirectGeneration($generation);
                        }
                    }
                }
            }
            $hasError = true;
        }

        if ($hasError) {
            // TODO Currently, the form has no element, so no validation and no automatic filling.
            $this->messenger()->addError('An error occurred: check your input.'); // @translate
            $this->messenger()->addFormErrors($form);
            // So create a fake generation to fill form.
            $generation = $this->fakeGeneration($post);
        } else {
            $generation = null;
        }

        /** @var \Generate\View\Helper\GenerationFields $generationFields */
        $generationFields = $this->viewHelpers()->get('generationFields');
        $fields = $generationFields(null, $generation, $resourceTemplate);

        // Only items can have a sub resource template for medias.
        // A media template may have no fields but it should be prepared anyway.
        if (in_array($resourceName, ['generations', 'items']) && $generative->generativeMedia()) {
            $resourceTemplateMedia = $generative->generativeMedia()->template();
            $fieldsByMedia = [];
            foreach ($generation ? array_keys($generation->proposalMedias()) : [] as $indexProposalMedia) {
                // TODO Match resource medias and generation (for now only allowed until submission).
                $indexProposalMedia = (int) $indexProposalMedia;
                $fieldsByMedia[] = $generationFields(null, $generation, $resourceTemplateMedia, true, $indexProposalMedia);
            }
            // Add a list of fields without values for new media.
            $fieldsMediaBase = $generationFields(null, $generation, $generative->generativeMedia()->template(), true);
        } else {
            $resourceTemplateMedia = null;
            $fieldsByMedia = [];
            $fieldsMediaBase = [];
        }

        $view = new ViewModel([
            'site' => $site,
            'user' => $user,
            'form' => $form,
            'resourceTemplate' => $resourceTemplate,
            'generation' => null,
            'resource' => null,
            'fields' => $fields,
            'templateMedia' => $resourceTemplateMedia,
            'fieldsByMedia' => $fieldsByMedia,
            'fieldsMediaBase' => $fieldsMediaBase,
            'action' => 'add',
            'mode' => 'write',
            'space' => $space,
        ]);
        return $view
            ->setTemplate($space === 'guest'
                ? 'guest/site/guest/generation-edit'
                : 'generate/site/generation/edit'
            );
    }

    /**
     * Edit a new generation or an existing item.
     *
     * Indeed, there are two types of edition:
     * - edit a generation not yet approved, so the user is editing his
     *   generation, one or multiple times;
     * - edit an existing item or resource, so this is a correction and each
     *   correction is a new correction (or a patch).
     *
     * It is always possible to correct an item, but a new generation cannot
     * be modified after validation.
     *
     * Furthermore, the edition of a new generation can be done in multi-steps
     * (template choice, metadata, files and medatada of files).
     *
     * @todo Separate all possible workflows.
     * @todo Move all the process to prepare data to view helper conributionForm().
     *
     * @return mixed|\Laminas\View\Model\ViewModel|\Laminas\Http\Response
     */
    public function editAction()
    {
        $params = $this->params();
        $mode = ($params->fromPost('mode') ?? $params->fromQuery('mode', 'write')) === 'read' ? 'read' : 'write';
        $isModeRead = $mode === 'read';
        $isModeWrite = !$isModeRead;
        $next = $params->fromQuery('next') ?? $params->fromPost('next') ?? '';
        if ($isModeRead && strpos($next, 'template') !== false) {
            $params = $params->fromRoute();
            $params['action'] = 'add';
            return $this->forward()->dispatch('Generate\Controller\Site\Generation', $params);
        }

        $site = $this->currentSite();
        $api = $this->api();
        $resourceType = $params->fromRoute('resource');
        $resourceId = $params->fromRoute('id');

        // Unlike addAction(), edition is always the right generation or
        // resource.
        $resourceTypeMap = [
            'generation' => 'generations',
            'item' => 'items',
            'media' => 'media',
            'item-set' => 'item_sets',
        ];
        // Useless, because managed by route, but the config may be overridden.
        if (!isset($resourceTypeMap[$resourceType])) {
            return $this->notFoundAction();
        }

        $resourceName = $resourceTypeMap[$resourceType];

        // Rights are automatically checked.
        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        $resource = $api->read($resourceName, ['id' => $resourceId])->getContent();

        $user = $this->identity();

        $canGenerate = $this->viewHelpers()->get('canGenerate');
        $canEditWithoutToken = $canGenerate();

        // This is a generation or a correction.
        $isGeneration = $resourceName === 'generations';
        if ($isGeneration) {
            /**
             * @var \Generate\Api\Representation\GenerationRepresentation|null $generation
             * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation|null $resource
             */
            $generation = $resource;
            $resource = $generation->resource();
            $resourceTemplate = $generation->resourceTemplate();
            $currentUrl = $this->url()->fromRoute(null, [], true);
        } else {
            $generation = null;
            $token = $this->checkToken($resource);
            if (!$token && !$canEditWithoutToken) {
                return $this->viewError403();
            }

            // There may be no generation when it is a correction.
            // But if a user edit a resource, the last generation is used.
            // Nevertheless, the user should be able to see previous corrections
            // and to do a new correction.
            if ($token) {
                $generation = $api
                    ->searchOne('generations', ['resource_id' => $resourceId, 'token_id' => $token->id(), 'patch' => true, 'sort_by' => 'id', 'sort_order' => 'desc'])
                    ->getContent();
                $currentUrl = $this->url()->fromRoute(null, [], ['query' => ['token' => $token->token()]], true);
            } elseif ($user) {
                $generation = $api
                    ->searchOne('generations', ['resource_id' => $resourceId, 'owner_id' => $user->getId(), 'patch' => true, 'sort_by' => 'id', 'sort_order' => 'desc'])
                    ->getContent();
                $currentUrl = $this->url()->fromRoute(null, [], true);
            } else {
                // An anonymous user cannot see existing generations.
                $generation = null;
                $currentUrl = $this->url()->fromRoute(null, [], true);
            }

            $resourceTemplate = $resource->resourceTemplate();
        }

        $space = $this->params('space', 'default');

        /** @var \Generate\Mvc\Controller\Plugin\GenerativeData $generative */
        $generative = clone $this->generativeData($resourceTemplate);
        if (!$resourceTemplate || !$generative->isGenerative()) {
            $this->logger()->warn('This resource cannot be edited: no resource template, no fields, or not allowed.'); // @translate
            $view = new ViewModel([
                'site' => $site,
                'user' => $user,
                'form' => null,
                'resourceTemplate' => $resourceTemplate,
                'generation' => $generation,
                'resource' => $resource,
                'fields' => [],
                'templateMedia' => null,
                'fieldsByMedia' => [],
                'fieldsMediaBase' => [],
                'action' => 'edit',
                'mode' => 'read',
                'space' => $space,
            ]);
            return $view
                ->setTemplate($space === 'guest'
                    ? 'guest/site/guest/generation-edit'
                    : 'generate/site/generation/edit'
                );
        }

        // $formOptions = [
        // ];

        /** @var \Generate\Form\GenerateForm $form */
        $form = $this->getForm(GenerateForm::class)
            ->setAttribute('action', $currentUrl)
            ->setAttribute('enctype', 'multipart/form-data')
            ->setAttribute('id', 'edit-resource');

        if ($isModeRead) {
            $form->setAttribute('class', 'readonly');
            $form->get('template')->setAttribute('readonly', 'readonly');
            $form->get('submit')->setAttribute('disabled', 'disabled');
            $form->get('mode')->setValue('read');
        }

        $allowUpdate = $this->settings()->get('generate_allow_update');
        $allowUpdateUntilValidation = $allowUpdate === 'validation';
        $isCorrection = !$generation || $generation->isPatch();

        // TODO Use method isUpdatable().
        if (!$isCorrection
            && $isModeWrite
            && !$allowUpdateUntilValidation
            && $generation
            && $generation->isSubmitted()
        ) {
            $this->messenger()->addWarning('This generation has been submitted and cannot be edited.'); // @translate
            return $this->redirect()->toRoute($space === 'guest' ? 'site/guest/generation-id' : 'site/generation-id', ['action' => 'view'], true);
        }

        // When a user wants to edit a resource, create a new correction.
        if ($isCorrection
            && $isModeWrite
            && !$allowUpdateUntilValidation
            && $generation
            && $generation->isSubmitted()
        ) {
            $generation = null;
        } elseif ($isCorrection
            && $isModeWrite
            && $allowUpdateUntilValidation
            && $generation
            && $generation->isReviewed()
        ) {
            $generation = null;
        }

        // No need to set the template, but simplify view for form.
        $form->get('template')->setValue($resourceTemplate->id());

        // There is no step for edition: the resource template is always set.

        $hasError = false;
        if ($this->getRequest()->isPost()) {
            $post = $params->fromPost();
            // The template cannot be changed once set.
            $post['template'] = $resourceTemplate->id();
            $form->setData($post);
            // TODO There is no check currently (html form), except the csrf.
            if ($isModeWrite && $form->isValid()) {
                // $data = $form->getData();
                $data = array_diff_key($post, ['csrf' => null, 'edit-resource-submit' => null]);
                $data = $this->checkAndIncludeFileData($data);
                if (empty($data['has_error'])) {
                    $proposal = $this->prepareProposal($data, $resource);
                    if ($proposal) {
                        // The resource isn’t updated, but the proposition of
                        // generate is saved for moderation.
                        $response = null;
                        if (empty($generation)) {
                            $data = [
                                'o:resource' => $resourceId ? ['o:id' => $resourceId] : null,
                                'o:owner' => $user ? ['o:id' => $user->getId()] : null,
                                'o-module-generate:token' => $token ? ['o:id' => $token->id()] : null,
                                'o:email' => $token ? $token->email() : ($user ? $user->getEmail() : null),
                                // Here, it's always a patch, else use "add".
                                'o-module-generate:patch' => true,
                                // A patch is always a submission.
                                'o-module-generate:submitted' => true,
                                'o-module-generate:reviewed' => false,
                                'o-module-generate:proposal' => $proposal,
                            ];
                            $response = $this->api($form)->create('generations', $data);
                            if ($response) {
                                $this->messenger()->addSuccess('Generation successfully submitted!'); // @translate
                                // $this->prepareGenerationEmail($response->getContent(), 'submit');
                            }
                        } elseif ($generation->isSubmitted() && !$allowUpdateUntilValidation) {
                            $this->messenger()->addWarning('This generation is already submitted and cannot be updated.'); // @translate
                            $response = $this->api()->read('generations', $generation->id());
                        } elseif ($proposal === $generation->proposal()) {
                            $this->messenger()->addWarning('No change.'); // @translate
                            $response = $this->api()->read('generations', $generation->id());
                        } else {
                            $data = [
                                'o-module-generate:reviewed' => false,
                                'o-module-generate:proposal' => $proposal,
                            ];
                            $response = $this->api($form)->update('generations', $generation->id(), $data, [], ['isPartial' => true]);
                            if ($response) {
                                $message = $this->settings()->get('generate_message_edit');
                                if ($message) {
                                    $this->messenger()->addSuccess($message);
                                } else {
                                    $this->messenger()->addSuccess('Generation successfully updated!'); // @translate
                                }
                                // $this->prepareGenerationEmail($response->getContent(), 'update');
                            }
                        }
                        if ($response) {
                            $eventManager = $this->getEventManager();
                            $eventManager->trigger('generate.submit', $this, [
                                'generation' => $generation,
                                'resource' => $resource,
                                'data' => $data,
                            ]);
                            /** @var \Generate\Api\Representation\GenerationRepresentation $generation $content */
                            $generation = $response->getContent();
                            return $generation->resource()
                                ? $this->redirect()->toUrl($generation->resource()->siteUrl())
                                : $this->redirectGeneration($generation);
                        }
                    }
                }
            }
            $hasError = $isModeWrite;
        }

        if (strpos($next, 'template') !== false) {
            $params = $params->fromRoute();
            $params['action'] = 'add';
            return $this->forward()->dispatch('Generate\Controller\Site\Generation', $params);
        }

        if ($hasError) {
            // TODO Currently, the form has no element, so no validation and no automatic filling.
            $this->messenger()->addError('An error occurred: check your input.'); // @translate
            $this->messenger()->addFormErrors($form);
            // So create a fake generation to fill form.
            $generation = $this->fakeGeneration($post, $generation);
        }

        /** @var \Generate\View\Helper\GenerationFields $generationFields */
        $generationFields = $this->viewHelpers()->get('generationFields');
        $fields = $generationFields($resource, $generation);

        // Only items can have a sub resource template for medias.
        // A media template may have no fields but it should be prepared anyway.
        if (in_array($resourceName, ['generations', 'items']) && $generative->generativeMedia()) {
            $resourceTemplateMedia = $generative->generativeMedia()->template();
            $fieldsByMedia = [];
            foreach ($generation ? array_keys($generation->proposalMedias()) : [] as $indexProposalMedia) {
                // TODO Match resource medias and generation (for now only allowed until submission).
                $indexProposalMedia = (int) $indexProposalMedia;
                $fieldsByMedia[] = $generationFields(null, $generation, $resourceTemplateMedia, true, $indexProposalMedia);
            }
            // Add a list of fields without values for new media.
            $fieldsMediaBase = $generationFields(null, null, $generative->generativeMedia()->template(), true);
        } else {
            $resourceTemplateMedia = null;
            $fieldsByMedia = [];
            $fieldsMediaBase = [];
        }

        $view = new ViewModel([
            'site' => $site,
            'user' => $user,
            'form' => $form,
            'resourceTemplate' => $resourceTemplate,
            'generation' => $generation,
            'resource' => $resource,
            'fields' => $fields,
            'templateMedia' => $resourceTemplateMedia,
            'fieldsByMedia' => $fieldsByMedia,
            'fieldsMediaBase' => $fieldsMediaBase,
            'action' => 'edit',
            'mode' => $mode,
            'space' => $space,
        ]);
        return $view
            ->setTemplate($space === 'guest'
                ? 'guest/site/guest/generation-edit'
                : 'generate/site/generation/edit'
            );
    }

    public function deleteConfirmAction(): void
    {
        throw new \Omeka\Mvc\Exception\PermissionDeniedException('The delete confirm action is currently unavailable'); // @translate
    }

    public function deleteAction()
    {
        $id = $this->params('id');
        $space = $this->params('space', 'default');

        if (!$this->getRequest()->isPost()) {
            $this->messenger()->addError(new PsrMessage('Deletion can be processed only with a post.')); // @translate
            return $this->redirect()->toRoute($space === 'guest' ? 'site/guest/generation-id' : 'site/generation-id', ['action' => 'show'], true);
        }

        $allowUpdate = $this->settings()->get('generate_allow_update') ?: 'submission';
        if ($allowUpdate === 'no') {
            $this->messenger()->addWarning('A generation cannot be updated or deleted.'); // @translate
            return $this->redirect()->toRoute($space === 'guest' ? 'site/guest/generation-id' : 'site/generation-id', ['action' => 'view'], true);
        }

        $resource = $this->api()->read('generations', $id)->getContent();

        if ($allowUpdate !== 'validation' && $resource->isSubmitted()) {
            $this->messenger()->addWarning('This generation has been submitted and cannot be deleted.'); // @translate
            return $this->redirect()->toRoute($space === 'guest' ? 'site/guest/generation-id' : 'site/generation-id', ['action' => 'view'], true);
        }
        if ($allowUpdate === 'validation' && $resource->isReviewed()) {
            $this->messenger()->addWarning('This generation has been reviewed and cannot be deleted.'); // @translate
            return $this->redirect()->toRoute($space === 'guest' ? 'site/guest/generation-id' : 'site/generation-id', ['action' => 'view'], true);
        }

        $response = $this->api()->delete('generations', $id);
        if ($response) {
            $this->messenger()->addSuccess('Generation successfully deleted.'); // @translate
        } else {
            $this->messenger()->addError('An issue occurred and the generation was not deleted.'); // @translate
        }

        // Warning: the js reload the page, so this redirect is not used.
        return $space === 'guest'
            ? $this->redirect()->toRoute('site/guest/generation', ['controller' => 'guest-board', 'action' => 'browse'], true)
            // TODO Update route when a main public browse of generations will be available.
            // TODO Check this redirect. Is it delete?
            : $this->redirect()->toRoute('site', [], true);
    }

    public function submitAction()
    {
        $resourceType = $this->params('resource');
        $resourceId = $this->params('id');
        $space = $this->params('space', 'default');

        // Unlike addAction(), submission is always the right generation or
        // resource.
        $resourceTypeMap = [
            'generation' => 'generations',
            'item' => 'items',
            'media' => 'media',
            'item-set' => 'item_sets',
        ];
        // Useless, because managed by route, but the config may be overridden.
        if (!isset($resourceTypeMap[$resourceType])) {
            return $this->notFoundAction();
        }

        $resourceName = $resourceTypeMap[$resourceType];

        // Only whole generation can be submitted: a patch is always submitted
        // directly.
        if ($resourceName !== 'generations') {
            // TODO The user won't see this warning.
            $this->messenger()->addWarning('Only a whole generation can be submitted.'); // @translate
            return $this->redirect()->toRoute('site/resource-id', ['action' => 'show'], true);
        }

        $api = $this->api();

        // Rights are automatically checked.
        /** @var \Generate\Api\Representation\GenerationRepresentation $generation */
        $generation = $api->read('generations', ['id' => $resourceId])->getContent();

        $allowUpdate = $this->settings()->get('generate_allow_update') ?: 'submission';

        if (!$generation->userIsAllowed('update')) {
            $this->messenger()->addError('Only the generator can update a generation.'); // @translate
            return $this->redirect()->toRoute($space === 'guest' ? 'site/guest/generation-id' : 'site/generation-id', ['action' => 'view'], true);
        }

        if ($allowUpdate !== 'validation' && $generation->isSubmitted()) {
            $this->messenger()->addWarning('This generation has already been submitted.'); // @translate
            return $this->redirect()->toRoute($space === 'guest' ? 'site/guest/generation-id' : 'site/generation-id', ['action' => 'view'], true);
        }
        if ($allowUpdate === 'validation' && $generation->isReviewed()) {
            $this->messenger()->addWarning('This generation has already been reviewed.'); // @translate
            return $this->redirect()->toRoute($space === 'guest' ? 'site/guest/generation-id' : 'site/generation-id', ['action' => 'view'], true);
        }

        // Validate the generation with the generation process.
        $resourceData = $generation->proposalToResourceData();
        if (!$resourceData) {
            $message = new PsrMessage(
                'Generation is not valid: check template.' // @translate
            );
            $this->messenger()->addError($message); // @translate
            return $this->redirect()->toRoute($space === 'guest' ? 'site/guest/generation-id' : 'site/generation-id', ['action' => 'view'], true);
        }

        // Validate the generation with the api process.
        $errorStore = new ErrorStore();
        $this->validateOrCreateOrUpdate($generation, $resourceData, $errorStore, false, true, true);
        if ($errorStore->hasErrors()) {
            return $this->redirect()->toRoute($space === 'guest' ? 'site/guest/generation-id' : 'site/generation-id', ['action' => 'view'], true);
        }

        $data = [];
        $data['o-module-generate:submitted'] = true;
        $response = $api
            ->update('generations', $resourceId, $data, [], ['isPartial' => true]);
        if (!$response) {
            $this->messenger()->addError('An error occurred: check your submission or ask an administrator.'); // @translate
            return $this->jsonErrorUpdate();
        }

        $this->messenger()->addSuccess('Generation successfully submitted!'); // @translate
        $generation = $response->getContent();
        $this
            ->notifyGeneration($generation, 'submit')
            ->confirmGeneration($generation, 'submit');

        return $this->redirect()->toRoute($space === 'guest' ? 'site/guest/generation-id' : 'site/generation-id', ['action' => 'view'], true);
    }

    /**
     * Manage a special redirection in order to manage complex form workflow.
     *
     * It uses the post value "next" (generally hidden), that can be overridden
     * by the query key "next".
     *
     * It allows to add files when separated in the form or to use a specific
     * show view to confirm.
     * Next step can be another "add", "edit" or "show" (default).
     * A query can be appended, separated with a "-", to be used in theme.
     */
    protected function redirectGeneration(GenerationRepresentation $generation)
    {
        $params = $this->params();
        $next = $params->fromQuery('next') ?? $params->fromPost('next') ?? '';
        $space = $this->params('space', 'default');
        if (!$next) {
            return $this->redirect()->toUrl($generation->siteUrl(null, false, 'view', $space === 'guest'));
        }
        [$nextAction, $nextQuery] = strpos($next, '-') === false ? [$next, null] : explode('-', $next, 2);
        if (!$nextAction || $nextAction === 'show' || $nextAction === 'view') {
            $nextAction = null;
        }
        if ($nextQuery) {
            $nextQuery = '?next=' . rawurlencode($next);
        }
        return $this->redirect()->toUrl($generation->siteUrl(null, false, $nextAction, $space === 'guest') . $nextQuery);
    }

    /**
     * Create a fake generation with data proposal.
     *
     * Should be used only for post issue: only data proposal are set and should
     * be used.
     *
     * @todo Remove fake generation with a real form.
     */
    protected function fakeGeneration(array $data, ?GenerationRepresentation $generation = null): GenerationRepresentation
    {
        $adapterManager = $this->currentSite()->getServiceLocator()->get('Omeka\ApiAdapterManager');
        $generationAdapter = $adapterManager->get('generations');

        $entity = new \Generate\Entity\Generation();
        if ($generation) {
            if ($resource = $generation->resource()) {
                $entity->setResource($this->api()->read('resources', ['id' => $resource->id()], ['responseContent' => 'resource'])->getContent());
            }
            $entity->setReviewed($generation->isReviewed());
        }

        unset($data['csrf'], $data['edit-resource-submit']);
        $proposal = $this->prepareProposal($data) ?: [];
        $entity->setProposal($proposal);

        return new GenerationRepresentation($entity, $generationAdapter);
    }

    protected function notifyGeneration(GenerationRepresentation $generation, string $action = 'update'): self
    {
        $emails = $this->filterEmails($generation);
        if (empty($emails)) {
            return $this;
        }

        $translate = $this->getPluginManager()->get('translate');
        $actions = [
            'prepare' => $translate('prepare'), // @translate
            'update' => $translate('update'), // @translate
            'submit' => $translate('submit'), // @translate
        ];

        $action = isset($actions[$action]) ? $action : 'update';
        $actionMsg = $actions[$action];
        $generationResource = $generation->resource();
        $user = $this->identity();

        $settings = $this->settings();
        $subject = $settings->get('generate_reviewer_confirmation_subject') ?: sprintf($translate('[Omeka] Generation %s'), $action);
        $message = $settings->get('generate_reviewer_confirmation_body');

        /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template */
        $template = $generation->resourceTemplate();
        if ($template) {
            $subject = $template->dataValue('generate_reviewer_confirmation_subject') ?: $subject;
            $message = $template->dataValue('generate_reviewer_confirmation_body') ?: $message;
        }

        if ($message) {
            $message = $this->replacePlaceholders($message, $generation);
            $this->sendGenerationEmail($emails, $subject, $message); // @translate
            return $this;
        }

        // Default message.
        switch (true) {
            case $generationResource && $user:
                $message = '<p>' . new PsrMessage(
                    'User {user} has made a generation for resource #{resource} ({title}) (action: {action}).', // @translate
                    [
                        'user' => '<a href="' . $this->url()->fromRoute('admin/id', ['controller' => 'user', 'id' => $user->getId()], ['force_canonical' => true]) . '">' . $user->getName() . '</a>',
                        'resource' => '<a href="' . $generationResource->adminUrl('show', true) . '#generation">' . $generationResource->id() . '</a>',
                        'title' => $generationResource->displayTitle(),
                        'action' => $actionMsg,
                    ]
                ) . '</p>';
                break;
            case $generationResource:
                $message = '<p>' . new PsrMessage(
                    'An anonymous user has made a generation for resource {resource} ({title}) (action: {action}).', // @translate
                    [
                        'resource' => '<a href="' . $generationResource->adminUrl('show', true) . '#generation">' . $generationResource->id() . '</a>',
                        'title' => $generationResource->displayTitle(),
                        'action' => $actionMsg,
                    ]
                ) . '</p>';
                break;
            case $user:
                $message = '<p>' . new PsrMessage(
                    'User {user} has made a generation (action: {action}).', // @translate
                    [
                        'user' => '<a href="' . $this->url()->fromRoute('admin/id', ['controller' => 'user', 'id' => $user->getId()], ['force_canonical' => true]) . '">' . $user->getName() . '</a>',
                        'action' => $actionMsg
                    ]
                ) . '</p>';
                break;
            default:
                $message = '<p>' . new PsrMessage(
                    'An anonymous user has made a generation (action: {action}).', // @translate
                    ['action' => $actionMsg]
                ) . '</p>';
                break;
        }

        $this->sendGenerationEmail($emails, $subject, $message); // @translate
        return $this;
    }

    protected function confirmGeneration(GenerationRepresentation $generation, string $action = 'update'): self
    {
        $settings = $this->settings();
        $confirms = $settings->get('generate_author_confirmations', []);
        if (empty($confirms) || !in_array($action, $confirms)) {
            return $this;
        }

        $emails = $this->authorEmails($generation);
        if (empty($emails)) {
            $this->messenger()->err('The author of this generation has no valid email. Check it or check the config.'); // @translate
            return $this;
        }

        $translate = $this->getPluginManager()->get('translate');

        $subject = $settings->get('generate_author_confirmation_subject') ?: $translate('[Omeka] Generation');
        $message = $settings->get('generate_author_confirmation_body') ?: new PsrMessage(
            "Hi,\nThanks for your generation.\n\nThe administrators will validate it as soon as possible.\n\nSincerely," // @translate
        );

        /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template */
        $template = $generation->resourceTemplate();
        if ($template) {
            $subject = $template->dataValue('generate_author_confirmation_subject') ?: $subject;
            $message = $template->dataValue('generate_author_confirmation_body') ?: $message;
        }

        $message = $this->replacePlaceholders($message, $generation);

        $message = '<p>' . $message . '</p>';

        $name = count($emails) === 1 && $generation->owner() ? $generation->owner()->name() : null;

        $this->sendGenerationEmail($emails, $subject, $message, $name); // @translate
        return $this;
    }

    protected function replacePlaceholders($message, ?GenerationRepresentation $generation): string
    {
        if (strpos($message, '{') === false || !$generation) {
            return (string) $message;
        }

        $url = $this->viewHelpers()->get('url');
        $api = $this->api();
        $settings = $this->settings();

        $replace = [];
        foreach ($generation->proposalToResourceData() as $term => $value) {
            if (!is_array($value) || empty($value) || !isset(reset($value)['type'])) {
                continue;
            }
            $first = reset($value);
            if (!empty($first['@id'])) {
                $replace['{' . $term . '}'] = $first['@id'];
            } elseif (!empty($first['value_resource_id'])) {
                try {
                    $replace['{' . $term . '}'] = $api->read('resources', ['id' => $first['value_resource_id']], [], ['initialize' => false, 'finalize' => false])->getContent()->getTitle();
                } catch (\Exception $e) {
                    $replace['{' . $term . '}'] = $this->translate('[Unknown resource]'); // @translate
                }
            } elseif (isset($first['@value']) && strlen((string) $first['@value'])) {
                $replace['{' . $term . '}'] = $first['@value'];
            }
        }

        if ($generation) {
            $replace['{resource_id}'] = $generation->id();
            $owner = $generation->owner();
            $replace['{user_name}'] = $owner ? $owner->name() : $this->translate('[Anonymous]'); // @translate
            $replace['{user_id}'] = $owner ? $owner->id() : 0;
            $replace['{user_email}'] = $generation->email();
            // Like module Contact Us.
            $replace['{email}'] = $generation->email();
        }

        $replace['{main_title}'] = $settings->get('installation_title', 'Omeka S');
        $replace['{main_url}'] = $url('top', [], ['force_canonical' => true]);
        // TODO Currently, the site is not stored, so use main title and main url.
        $replace['{site_title}'] = $replace['{main_title}'];
        $replace['{site_url}'] = $replace['{main_url}'];

        // TODO Store and add ip.

        return str_replace(array_keys($replace), array_values($replace), $message);
    }

    protected function filterEmails(?GenerationRepresentation $generation = null): array
    {
        $emails = $this->settings()->get('generate_notify_recipients', []);
        if (empty($emails)) {
            return [];
        }

        if (!$generation) {
            return $emails;
        }

        $result = [];
        foreach ($emails as $email) {
            [$email, $query] = explode(' ', $email . ' ', 2);
            if ($email
                && filter_var($email, FILTER_VALIDATE_EMAIL)
                && $generation->match($query)
            ) {
                $result[] = $email;
            }
        }

        return $result;
    }

    protected function authorEmails(?GenerationRepresentation $generation = null): array
    {
        $emails = [];
        $propertyEmails = $this->settings()->get('generate_author_emails', ['owner'])  ?: ['owner'];

        /*
        if ($generation && !in_array('owner', $propertyEmails)) {
            $propertyEmails[] = 'owner';
        }
        */

        $resourceData = $generation ? $generation->proposalToResourceData() : [];

        foreach ($propertyEmails as $propertyEmail) {
            if ($propertyEmail === 'owner') {
                $owner = $generation ? $generation->owner() : null;
                if ($owner) {
                    $emails[] = $owner->email();
                }
            } elseif (strpos($propertyEmail, ':') && !empty($resourceData[$propertyEmail])) {
                foreach ($resourceData[$propertyEmail] as $resourceValue) {
                    if (isset($resourceValue['@value'])) {
                        $emails[] = $resourceValue['@value'];
                    }
                }
            }
        }

        foreach ($emails as $key => $email) {
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                unset($emails[$key]);
            }
        }

        return $emails;
    }

    /**
     * Prepare the proposal for saving.
     *
     * The check is done comparing the keys of original values and the new ones.
     *
     * @todo Factorize with \Generate\View\Helper\GenerationFields
     * @todo Factorize with \Generate\Api\Representation\GenerationRepresentation::proposalNormalizeForValidation()
     * @todo Factorize with \Generate\Api\Representation\GenerationRepresentation::proposalToResourceData()
     *
     * @todo Simplify when the status "is patch" or "new resource" (at least remove all original data).
     */
    protected function prepareProposal(
        array $proposal,
        ?AbstractResourceEntityRepresentation $resource = null,
        ?bool $isSubTemplate = false
    ): ?array {
        $isSubTemplate = (bool) $isSubTemplate;

        // It's not possible to change the resource template of a resource in
        // public side.
        // A resource can be corrected only with a resource template (require
        // editable or fillable keys).
        if ($resource) {
            $resourceTemplate = $resource->resourceTemplate();
        } elseif (isset($proposal['template'])) {
            $resourceTemplate = $proposal['template'] ?? null;
            $resourceTemplate = $this->api()->searchOne('resource_templates', is_numeric($resourceTemplate) ? ['id' => $resourceTemplate] : ['label' => $resourceTemplate])->getContent();
        } else {
            $resourceTemplate = null;
        }
        if (!$resourceTemplate) {
            return null;
        }

        // The generation requires a resource template in allowed templates.
        /** @var \Generate\Mvc\Controller\Plugin\GenerativeData $generative */
        $generative = clone $this->generativeData($resourceTemplate, $isSubTemplate);
        if (!$generative->isGenerative()) {
            return null;
        }

        $resourceTemplate = $generative->template();
        $result = [
            'template' => $resourceTemplate->id(),
            'media' => [],
        ];

        // File is specific: for media only, one value only, not updatable,
        // not a property and not in resource template.
        if (isset($proposal['file'][0]['@value']) && $proposal['file'][0]['@value'] !== '') {
            $store = $proposal['file'][0]['store'] ?? null;
            $result['file'] = [];
            $result['file'][0] = [
                'original' => [
                    '@value' => null,
                ],
                'proposed' => [
                    '@value' => $proposal['file'][0]['@value'],
                    $store ? 'store' : 'file' => $store ?? $proposal['file'][0]['file'],
                ],
            ];
        }

        // Clean data for the special keys.
        $proposalMedias = $isSubTemplate ? [] : ($proposal['media'] ?? []);
        unset($proposal['template'], $proposal['media']);

        foreach ($proposal as &$values) {
            // Manage specific posts.
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as &$value) {
                if (isset($value['@value'])) {
                    $value['@value'] = $this->cleanString($value['@value']);
                }
                if (isset($value['@resource'])) {
                    $value['@resource'] = (int) $value['@resource'];
                }
                if (isset($value['@uri'])) {
                    $value['@uri'] = $this->cleanString($value['@uri']);
                }
                if (isset($value['@label'])) {
                    $value['@label'] = $this->cleanString($value['@label']);
                }
                if (isset($value['@language'])) {
                    $value['@language'] = $this->cleanString($value['@language']);
                }
            }
        }
        unset($values, $value);

        /** @var \Common\Stdlib\EasyMeta $easyMeta */
        $easyMeta = $this->easyMeta()();
        $propertyIds = $easyMeta->propertyIds();

        // Process only editable keys.

        // Process editable properties first.
        // TODO Remove whitelist/blacklist since a resource template is required (but take care of updated template).
        $matches = [];
        switch ($generative->editableMode()) {
            case 'whitelist':
                $proposalEditableTerms = array_keys(array_intersect_key($proposal, $generative->editableProperties()));
                break;
            case 'blacklist':
                $proposalEditableTerms = array_keys(array_diff_key($proposal, $generative->editableProperties()));
                break;
            case 'all':
            default:
                $proposalEditableTerms = array_keys($proposal);
                break;
        }

        foreach ($proposalEditableTerms as $term) {
            // File is a special type: for media only, single value only, not updatable.
            if ($term === 'file') {
                continue;
            }

            /** @var \Omeka\Api\Representation\ValueRepresentation[] $values */
            $values = $resource ? $resource->value($term, ['all' => true]) : [];
            foreach ($values as $index => $value) {
                if (!isset($proposal[$term][$index])) {
                    continue;
                }
                $dataType = $value->type();
                if (!$generative->isTermDataType($term, $dataType)) {
                    continue;
                }

                $mainTypeTemplate = $easyMeta->dataTypeMain($dataType);
                $isCustomVocab = substr((string) $dataType, 0, 12) === 'customvocab:';
                $isCustomVocabUri = $isCustomVocab && $mainTypeTemplate === 'uri';
                $uriLabels = $isCustomVocabUri ? $this->customVocabUriLabels($dataType) : [];

                // If a lang was set in the original value, it is kept, else use
                // the posted one, else use the default one of the template.
                $lang = $value->lang() ?: null;
                if (!$lang) {
                    if (!empty($proposal[$term][$index]['@language'])) {
                        $lang = $proposal[$term][$index]['@language'];
                    } else {
                        /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyRepresentation $templateProperty */
                        $templateProperty = $resourceTemplate->resourceTemplateProperty($value->property()->id());
                        if ($templateProperty) {
                            $lang = $templateProperty->mainDataValue('default_language') ?: null;
                        }
                    }
                }

                switch ($mainTypeTemplate) {
                    case 'literal':
                        if (!isset($proposal[$term][$index]['@value'])) {
                            continue 2;
                        }
                        $prop = [
                            'original' => [
                                '@value' => $value->value(),
                            ],
                            'proposed' => [
                                '@value' => $proposal[$term][$index]['@value'],
                            ],
                        ];
                        break;
                    case 'resource':
                        if (!isset($proposal[$term][$index]['@resource'])) {
                            continue 2;
                        }
                        $vr = $value->valueResource();
                        $prop = [
                            'original' => [
                                '@resource' => $vr ? $vr->id() : null,
                            ],
                            'proposed' => [
                                '@resource' => (int) $proposal[$term][$index]['@resource'] ?: null,
                            ],
                        ];
                        break;
                    case 'uri':
                        if (!isset($proposal[$term][$index]['@uri'])) {
                            continue 2;
                        }
                        if ($isCustomVocabUri) {
                            $proposal[$term][$index]['@label'] = $uriLabels[$proposal[$term][$index]['@uri']] ?? $proposal[$term][$index]['@label'] ?? '';
                        }
                        // Value suggest is stored as a link by js in form to
                        // get uri and label from the user.
                        elseif (preg_match('~^<a href="([^"]+)"[^>]*>\s*(.*)\s*</a>$~', $proposal[$term][$index]['@uri'], $matches)) {
                            if (!filter_var($matches[1], FILTER_VALIDATE_URL)) {
                                continue 2;
                            }
                            $proposal[$term][$index]['@uri'] = $matches[1];
                            $proposal[$term][$index]['@label'] = $matches[2];
                        } elseif (filter_var($proposal[$term][$index]['@uri'], FILTER_VALIDATE_URL)) {
                            $proposal[$term][$index]['@label'] ??= '';
                        } else {
                            continue 2;
                        }
                        $prop = [
                            'original' => [
                                '@uri' => $value->uri(),
                                '@label' => $value->value(),
                            ],
                            'proposed' => [
                                '@uri' => $proposal[$term][$index]['@uri'],
                                '@label' => $proposal[$term][$index]['@label'],
                            ],
                        ];
                        break;
                    default:
                        // Nothing to do.
                        continue 2;
                }
                if ($lang) {
                    $prop['proposed']['@language'] = $lang;
                }
                $result[$term][] = $prop;
            }
        }

        // Append fillable properties.
        switch ($generative->fillableMode()) {
            case 'whitelist':
                $proposalFillableTerms = array_keys(array_intersect_key($proposal, $generative->fillableProperties()));
                break;
            case 'blacklist':
                $proposalFillableTerms = array_diff_key($proposal, $generative->fillableProperties());
                break;
            case 'all':
            default:
                $proposalFillableTerms = array_keys($proposal);
                break;
        }

        foreach ($proposalFillableTerms as $term) {
            if (!isset($propertyIds[$term])) {
                continue;
            }

            /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyRepresentation $templateProperty */
            $templateProperty = null;
            $propertyId = $propertyIds[$term];
            $mainType = null;
            $typeTemplate = null;
            if ($resourceTemplate) {
                $templateProperty = $resourceTemplate->resourceTemplateProperty($propertyId);
                if ($templateProperty) {
                    $typeTemplate = $templateProperty->dataType();
                }
            }

            $mainTypeTemplate = $easyMeta->dataTypeMain($typeTemplate);
            $isCustomVocab = substr((string) $typeTemplate, 0, 12) === 'customvocab:';
            $isCustomVocabUri = $isCustomVocab && $mainTypeTemplate === 'uri';
            $uriLabels = $isCustomVocabUri ? $this->customVocabUriLabels($typeTemplate) : [];

            foreach ($proposal[$term] as $index => $proposedValue) {
                /** @var \Omeka\Api\Representation\ValueRepresentation[] $values */
                $values = $resource ? $resource->value($term, ['all' => true]) : [];
                if (isset($values[$index])) {
                    continue;
                }

                if ($typeTemplate) {
                    $mainType = $mainTypeTemplate;
                } elseif (array_key_exists('@uri', $proposedValue)) {
                    $mainType = 'uri';
                } elseif (array_key_exists('@resource', $proposedValue)) {
                    $mainType = 'resource';
                } elseif (array_key_exists('@value', $proposedValue)) {
                    $mainType = 'literal';
                } else {
                    $mainType = 'unknown';
                }

                if (!$generative->isTermDataType($term, $typeTemplate ?? $mainType)) {
                    continue;
                }

                // Use the posted language, else the default one of the template.
                if (!empty($proposedValue['@language'])) {
                    $lang = $proposedValue['@language'];
                } elseif ($templateProperty) {
                    $lang = $templateProperty->mainDataValue('default_language') ?: null;
                } else {
                    $lang = null;
                }

                switch ($mainType) {
                    case 'literal':
                        if (!isset($proposedValue['@value']) || $proposedValue['@value'] === '') {
                            continue 2;
                        }
                        $prop = [
                            'original' => [
                                '@value' => null,
                            ],
                            'proposed' => [
                                '@value' => $proposedValue['@value'],
                            ],
                        ];
                        break;
                    case 'resource':
                        if (!isset($proposedValue['@resource']) || !(int) $proposedValue['@resource']) {
                            continue 2;
                        }
                        $prop = [
                            'original' => [
                                '@resource' => null,
                            ],
                            'proposed' => [
                                '@resource' => (int) $proposedValue['@resource'],
                            ],
                        ];
                        break;
                    case 'uri':
                        if (!isset($proposedValue['@uri']) || $proposedValue['@uri'] === '') {
                            continue 2;
                        }
                        if ($isCustomVocabUri) {
                            $proposedValue['@label'] = $uriLabels[$proposedValue['@uri']] ?? $proposedValue['@label']  ?? '';
                        }
                        // Value suggest is stored as a link by js in form to
                        // get uri and label from the user.
                        elseif (preg_match('~^<a href="([^"]+)"[^>]*>\s*(.*)\s*</a>$~', $proposedValue['@uri'], $matches)) {
                            if (!filter_var($matches[1], FILTER_VALIDATE_URL)) {
                                continue 2;
                            }
                            $proposedValue['@uri'] = $matches[1];
                            $proposedValue['@label'] = $matches[2];
                        } elseif (filter_var($proposedValue['@uri'], FILTER_VALIDATE_URL)) {
                            $proposedValue['@label'] ??= '';
                        } else {
                            continue 2;
                        }
                        $prop = [
                            'original' => [
                                '@uri' => null,
                                '@label' => null,
                            ],
                            'proposed' => [
                                '@uri' => $proposedValue['@uri'],
                                '@label' => $proposedValue['@label'],
                            ],
                        ];
                        break;
                    default:
                        // Nothing to do.
                        continue 2;
                }
                if ($lang) {
                    $prop['proposed']['@language'] = $lang;
                }
                $result[$term][] = $prop;
            }
        }

        if (!$isSubTemplate) {
            $generativeMedia = $generative->generativeMedia();
            if ($generativeMedia) {
                $templateMedia = $generativeMedia->template()->id();
                foreach ($proposalMedias ?: [] as $indexProposalMedia => $proposalMedia) {
                    // TODO Currently, only new media are managed as sub-resource: generation for new resource, not generation for existing item with media at the same time.
                    $proposalMedia['template'] = $templateMedia;
                    $proposalMediaClean = $this->prepareProposal($proposalMedia, null, true);
                    // Skip empty media (without keys "template" and "media").
                    if (count($proposalMediaClean) > 2) {
                        $result['media'][$indexProposalMedia] = $proposalMediaClean;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Early check files and move data into main data.
     *
     * @todo Use the error store when the form will be ready and use only adapter anyway.
     */
    protected function checkAndIncludeFileData(array $data): array
    {
        $translate = $this->getPluginManager()->get('translate');
        $uploadErrorCodes = [
            UPLOAD_ERR_OK => $translate('File successfuly uploaded.'), // @translate
            UPLOAD_ERR_INI_SIZE => $translate('The total of file sizes exceeds the the server limit directive.'), // @translate
            UPLOAD_ERR_FORM_SIZE => $translate('The file size exceeds the specified limit.'), // @translate
            UPLOAD_ERR_PARTIAL => $translate('The file was only partially uploaded.'), // @translate
            UPLOAD_ERR_NO_FILE => $translate('No file was uploaded.'), // @translate
            UPLOAD_ERR_NO_TMP_DIR => $translate('The temporary folder to store the file is missing.'), // @translate
            UPLOAD_ERR_CANT_WRITE => $translate('Failed to write file to disk.'), // @translate
            UPLOAD_ERR_EXTENSION => $translate('A PHP extension stopped the file upload.'), // @translate
        ];

        // Make format compatible with default Omeka.
        // Only one file by media.
        $uploadeds = $this->getRequest()->getFiles()->toArray();
        $hasError = false;
        // TODO Support edition of a media directly (not in a sub template).
        foreach ($uploadeds['media'] ?? [] as $key => $mediaFiles) {
            $uploadeds['media'][$key]['file'] = empty($mediaFiles['file']) ? [] : array_values($mediaFiles['file']);
            foreach ($uploadeds['media'][$key]['file'] as $mediaFile) {
                $uploaded = $mediaFile['@value'];
                if (empty($uploaded) || $uploaded['error'] == UPLOAD_ERR_NO_FILE) {
                    unset($data['media'][$key]['file']);
                } elseif ($uploaded['error']) {
                    $hasError = true;
                    unset($data['media'][$key]['file']);
                    $this->messenger()->addError(new PsrMessage(
                        'File {key}: {error}', // @translate
                        ['key' => $key, 'error' => $uploadErrorCodes[$uploaded['error']]]
                    ));
                } elseif (!$uploaded['size']) {
                    $hasError = true;
                    unset($data['media'][$key]['file']);
                    $this->messenger()->addError(new PsrMessage(
                        'Empty file for key {key}', // @translate
                        ['key' => $key]
                    ));
                } else {
                    // Don't use uploader here, but only in adapter, else
                    // Laminas will believe it's an attack after renaming.
                    $tempFile = $this->tempFileFactory->build();
                    $tempFile->setSourceName($uploaded['name']);
                    $tempFile->setTempPath($uploaded['tmp_name']);
                    if (!(new \Omeka\File\Validator())->validate($tempFile)) {
                        $hasError = true;
                        unset($data['media'][$key]['file']);
                        $this->messenger()->addError(new PsrMessage(
                            'Invalid file type for key {key}', // @translate
                            ['key' => $key]
                        ));
                    } else {
                        // Take care of automatic rename of uploader (not used).
                        $data['media'][$key]['file'] = [
                            [
                                '@value' => $uploaded['name'],
                                'file' => $uploaded,
                            ],
                        ];
                    }
                }
            }
        }
        if ($hasError) {
            $data['error'] = true;
        }
        return $data;
    }

    /**
     * Get the list of uris and labels of a specific custom vocab.
     *
     * @see \Generate\Controller\GenerationTrait::customVocabUriLabels()
     * @see \Generate\Api\Representation\GenerationRepresentation::customVocabUriLabels()
     */
    protected function customVocabUriLabels(string $dataType): array
    {
        static $uriLabels = [];
        if (!isset($uriLabels[$dataType])) {
            $uriLabels[$dataType] = [];
            $customVocabId = (int) substr($dataType, 12);
            if ($customVocabId) {
                /** @var \CustomVocab\Api\Representation\CustomVocabRepresentation $customVocab */
                $customVocab = $this->api()->searchOne('custom_vocabs', ['id' => $customVocabId])->getContent();
                if ($customVocab) {
                    $uriLabels[$customVocabId] = $customVocab->listUriLabels() ?: [];
                }
            }
        }
        return $uriLabels[$customVocabId];
    }

    /**
     * Trim and normalize end of lines of a string.
     */
    protected function cleanString($string): string
    {
        return str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], trim((string) $string));
    }

    /**
     * Helper to return a message of error as normal view.
     */
    protected function viewError403(): ViewModel
    {
        // TODO Return a normal page instead of an exception.
        // throw new \Omeka\Api\Exception\PermissionDeniedException('Forbidden access.');
        $message = 'Forbidden access.'; // @translate
        $this->getResponse()
            ->setStatusCode(\Laminas\Http\Response::STATUS_CODE_403);
        $view = new ViewModel([
            'message' => $message,
        ]);
        return $view
            ->setTemplate('error/403');
    }
}
