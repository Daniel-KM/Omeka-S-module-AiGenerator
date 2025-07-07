<?php declare(strict_types=1);

namespace AiGenerator\Mvc\Controller\Plugin;

use Common\Stdlib\EasyMeta;
use Common\Stdlib\PsrMessage;
use AiGenerator\Api\Representation\AiRecordRepresentation;
use Laminas\Authentication\AuthenticationServiceInterface;
use Laminas\I18n\Translator\TranslatorInterface;
use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\ResourceTemplateRepresentation;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Mvc\Status;
use Omeka\Permissions\Acl;
use Omeka\Settings\Settings;
use Omeka\Stdlib\ErrorStore;
use OpenAI;

class GenerateViaOpenAi extends AbstractPlugin
{
    /**
     * The type of models is slightly mysterious. Furthermore, it can change at
     * any time, so it is recommended to append the date of the model.
     * Furthermore, some models does not support sending image separately.
     * Furthermore, some options are strange.
     *
     * @todo Clarify api use.
     *
     * @var array
     */
    protected $dataModels = [
        'gpt-4.1-nano' => [
            'messages' => true,
            'output' => 'tools',
            'separate_urls' => true,
            'stop' => "\n\n\n",
        ],
        'gpt-4.1-mini' => [
            'messages' => true,
            'output' => 'tools',
            'separate_urls' => true,
            'stop' => "\n\n\n",
        ],
        'gpt-4.1' => [
            'messages' => true,
            'output' => 'text',
            'separate_urls' => true,
            'stop' => "\n\n\n",
        ],
        'gpt-4o-mini' => [
            'messages' => true,
            'output' => 'tools',
            'separate_urls' => true,
            'stop' => "\n\n\n",
        ],
        'gpt-4o' => [
            'messages' => true,
            'output' => 'text',
            'separate_urls' => true,
            'stop' => "\n\n\n",
        ],
        // TODO How to support images with gpt-3.5?
        'gpt-3.5-turbo' => [
            'messages' => true,
            'output' => 'text',
            'separate_urls' => false,
            'stop' => null,
        ],
        'gpt-4.5-preview' => [
            'messages' => true,
            'output' => 'tools',
            'separate_urls' => true,
            'stop' => "\n\n\n",
        ],
    ];

    /**
     * @var \Omeka\Permissions\Acl
     */
    protected $acl;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Laminas\Authentication\AuthenticationServiceInterface
     */
    protected $authentication;

    /**
     * @var \Common\Stdlib\EasyMeta
     */
    protected $easyMeta;

    /**
     * @var \Laminas\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\Messenger
     */
    protected $messenger;

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    /**
     * @var \Omeka\Mvc\Status
     */
    protected $status;

    /**
     * @var \Laminas\I18n\Translator\TranslatorInterface
     */
    protected $translator;

    /**
     * @var \AiGenerator\Mvc\Controller\Plugin\ValidateRecordOrCreateOrUpdate
     */
    protected $validateRecordOrCreateOrUpdate;

    /**
     * @var string
     */
    protected $apiKey;

    /**
     * @var string|null
     */
    protected $organization;

    /**
     * @var string|null
     */
    protected $project;

    /**
     * @var boolean
     */
    protected $skipMessenger = false;

    public function __construct(
        Acl $acl,
        ApiManager $api,
        AuthenticationServiceInterface $authentication,
        EasyMeta $easyMeta,
        LoggerInterface $logger,
        Messenger $messenger,
        Settings $settings,
        Status $status,
        TranslatorInterface $translator,
        ValidateRecordOrCreateOrUpdate $validateRecordOrCreateOrUpdate,
        string $apiKey,
        ?string $organization,
        ?string $project
    ) {
        $this->acl = $acl;
        $this->api = $api;
        $this->authentication = $authentication;
        $this->easyMeta = $easyMeta;
        $this->logger = $logger;
        $this->messenger = $messenger;
        $this->settings = $settings;
        $this->status = $status;
        $this->translator = $translator;
        $this->validateRecordOrCreateOrUpdate = $validateRecordOrCreateOrUpdate;
        $this->apiKey = $apiKey;
        $this->organization = $organization;
        $this->project = $project;
    }

    /**
     * Generate resource metadata via OpenAI.
     *
     * @see https://packagist.org/packages/chatgptcom/chatgpt-php
     *
     * @var array $options
     * - model (string): the model to use.
     * - max_tokens (int): the max tokens to use for a request.
     * - derivative (string): the derivative image type or original to process.
     * - prompt_system (string): prompt for the system, that defines the context
     *   of the session. Empty string is allowed. Null means default prompt.
     * - prompt_user (string): specific prompt. May be a simple word when the
     *   context is enough. Empty string is allowed. Null means default prompt.
     * - process (string): "tools" (default) or "text". "tools" always outputs
     *   valid json, but it is twice the price of a simple textual prompt
     *   requesting json, but this one may be invalid.
     * - validate (bool): validate response automatically if user has rights.
     */
    public function __invoke(ItemRepresentation|MediaRepresentation $resource, array $options = []): ?AiRecordRepresentation
    {
        // Generating may be expensive, so there is a specific check for roles.
        $generateRoles = $this->settings->get('aigenerator_roles') ?: [];
        $user = $this->authentication->getIdentity();
        if (!$user || !in_array($user->getRole(), $generateRoles)) {
            return null;
        }

        if (!$this->apiKey) {
            $this->logger->err('OpenAI api key is undefined.'); // @translate
            return null;
        }

        $this->skipMessenger = !$this->status->isAdminRequest()
            && !$this->status->isSiteRequest();

        $models = $this->settings->get('aigenerator_models') ?: [];
        if (!$models) {
            $this->useMessenger || $this->messenger->addWarning(new PsrMessage(
                'The list of models is empty.' // @translate
            ));
            $this->logger->warn(
                '[AiGenerator] The list of models is empty.' // @translate
            );
            return null;
        }

        $model = ($options['model'] ?? null) === null
            ? trim((string) $this->settings->get('aigenerator_model'))
            : trim((string) $options['model']);
        if (!isset($models[$model])) {
            $this->skipMessenger || $this->messenger->addWarning(new PsrMessage(
                'The model "{model}" is not in the list of allowed models.', // @translate
                ['model' => $model]
            ));
            $this->logger->warn(
                '[AiGenerator] The model "{model}" is not in the list of allowed models.', // @translate
                ['model' => $model]
            );
            return null;
        }

        $maxTokens = ($options['max_tokens'] ?? null) === null
            ? (int) $this->settings->get('aigenerator_max_tokens')
            : (int) $options['max_tokens'];

        $derivative = empty($options['derivative'])
            ? $this->settings->get('aigenerator_derivative', 'medium')
            : $options['derivative'];
        $derivative = $derivative ?: 'medium';

        // The prompt for session or for user may be skipped, not the two.

        $promptSystem = ($options['prompt_system'] ?? null) === null
            ? trim((string) $this->settings->get('aigenerator_prompt_system'))
            : trim((string) $options['prompt_system']);

        $promptUser = ($options['prompt_user'] ?? null) === null
            ? trim((string) $this->settings->get('aigenerator_prompt_user'))
            : trim((string) $options['prompt_user']);

        if ($promptSystem === '' && $promptUser === '') {
            $this->skipMessenger || $this->messenger->addWarning(new PsrMessage(
                'No prompt is defined, so the record cannot be generated.' // @translate
            ));
            $this->logger->warn('[AiGenerator] Prompts are not defined.'); // @translate
            return null;
        }

        $validate = !empty($options['validate']);

        $isMedia = false;
        if ($resource instanceof ItemRepresentation) {
            $medias = $resource->media();
            if (!count($medias)) {
                $this->skipMessenger || $this->messenger->addWarning(new PsrMessage(
                    'The item has no file, so the record cannot be generated.' // @translate
                ));
                $this->logger->warn(
                    '[AiGenerator] The item #{item_id} has no file.', // @translate
                    ['item_id' => $resource->id()]
                );
                return null;
            }
        } elseif ($resource instanceof MediaRepresentation) {
            $isMedia = true;
            $medias = [$resource];
            return null;
        }

        // Get all media files.
        $urls = [];
        $useOriginal = $derivative === 'original';
        foreach ($medias as $media) {
            if ($media->renderer() === 'file'
                && (($useOriginal && $media->hasOriginal()) || (!$useOriginal && $media->hasThumbnails()))
                && strtok((string) $media->mediaType(), '/') === 'image'
            ) {
                $urls[$media->id()] = $this->urlOrBase64($media, $derivative);
            }
        }

        if (!count($urls)) {
            if ($isMedia) {
                $this->skipMessenger || $this->messenger->addWarning(new PsrMessage(
                    'The resource is not a file, not an image or the original file is missing, so the record cannot be generated.' // @translate
                ));
                $this->logger->warn(
                    '[AiGenerator] The media #{media_id} is not a file, not an image or the original file is missing.', // @translate
                    ['media_id' => $resource->id()]
                );
            } else {
                $this->skipMessenger || $this->messenger->addWarning(new PsrMessage(
                    'The item has no files, or no image or no original file, so the record cannot be generated.' // @translate
                ));
                $this->logger->warn(
                    '[AiGenerator] The item #{item_id} has no files, or no image or no original file.', // @translate
                    ['item_id' => $resource->id()]
                );
            }
            return null;
        }

        // The request and the response varies according to the model.

        // TODO Simplify: if text, append json and urls to the prompt; else create the tools.

        // "completions" is deprecated as main endpoint and the sub-endpoint
        // "chat/completions" does not seem to be supported, so use chat by
        // default, for textual data or json.

        $useProcess = isset($options['process'])
            ? (($options['process'] ?? null) === 'text' ? 'text' : 'tools')
            : ($this->dataModels[$model]['output'] ?? 'tools');

        // For completions, the response format is appended to prompt.
        // A text is output too to force json output.
        // So it is useless to append it to prompt or use a placeholder.
        // It is simpler than a
        if ($useProcess === 'text') {
            // Use response format for one-shot process with completions.
            [$promptSystem, $promptUser] = $this->completePrompts($promptSystem, $promptUser, $model, $resource, $urls);
        }

        $promptSystem = $this->preparePrompt($resource, $promptSystem, $urls);
        $promptUser = $this->preparePrompt($resource, $promptUser, $urls);

        $messages = [];
        if ($this->dataModels[$model]['messages'] ?? false) {
            if ($promptSystem) {
                $messages[] = [
                    'role' => 'system',
                    'content' => $promptSystem,
                ];
            }

            if ($promptUser) {
                $messageUser = [
                    'role' => 'user',
                    'content' => [],
                ];
                $messageUser['content'][] = [
                    'type' => 'text',
                    'text' => $promptUser,
                ];
            }

            if (!empty($this->dataModels[$model]['separate_urls'])) {
                $messageUser ??= [
                    'role' => 'user',
                    'content' => [],
                ];
                foreach ($urls as $url) {
                    $messageUser['content'][] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $url,
                        ],
                    ];
                }
            }

            if ($messageUser) {
                $messages[] = $messageUser;
            }
        }

        if ($useProcess === 'text') {
            // Use response format and completions.
            // Return deterministic metadata.
            $args = [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => $maxTokens,
                'temperature' => 0,
                'top_p' => 1,
                'frequency_penalty' => 0,
                'presence_penalty' => 0,
                'stop' => $this->dataModels[$model]['stop'] ?? null,
                // 'response_format' => 'json',
                // Sometime needed.
                // 'prompt' => $promptSystem ?: $promptUser,
            ];
        } else {
            // Use tools and chat for complex workflow with dialog
            // "functions" is deprecated, it is now sub-part of tools.
            $tools = $this->prepareTools($resource);
            if (!$tools || empty($tools[0]['function']['parameters']['properties'])) {
                $this->logger->err(
                    'The structure defined from the template is empty or incorrect.' // @translate
                );
                $this->logger->err(
                    '[AiGenerator] Error for resource #{resource_id}: the structure defined from the template is empty or incorrect.', // @translate
                    ['resource_id' => $resource->id()]
                );
                return null;
            }
            $args = [
                'model' => $model,
                'messages' => $messages,
                'tools' => $tools,
                'max_tokens' => $maxTokens,
                'temperature' => 0,
                'top_p' => 1,
                'frequency_penalty' => 0,
                'presence_penalty' => 0,
                'stop' => $this->dataModels[$model]['stop'] ?? null,
            ];
        }

        // Send the request to OpenAI.

        /** @var \OpenAI\Client $client */
        $client = OpenAI::client($this->apiKey, $this->organization, $this->project);

        try {
            /** @var \OpenAI\Responses\Chat\CreateResponse $response */
            if ($useProcess === 'text') {
                // "completions" is deprecated and work only with some models.
                // Anyway, the command can be similar than json.
                // $response = $client->completions()->create($args);
                $response = $client->chat()->create($args);
            } else {
                $response = $client->chat()->create($args);
            }
        } catch (\Exception $e) {
            $this->skipMessenger || $this->messenger->addError(new PsrMessage(
                'An exception occurred: {msg}', // @translate
                ['msg' => $e->getMessage()]
            ));
            $this->logger->err(
                '[AiGenerator] Exception for resource #{resource_id}: {msg}', // @translate
                ['resource_id' => $resource->id(), 'msg' => $e->getMessage()]
            );
            return null;
        }

        // Fill the generated resource.
        $resourceTemplate = $resource->resourceTemplate();

        /** @var \OpenAI\Responses\Chat\CreateResponseChoice $choice */
        $choice = $response->choices[0];

        if ($useProcess === 'text') {
            $content = $choice->message->content;
        } else {
            /** @var OpenAI\Responses\Chat\CreateResponseToolCall $toolCall */
            // There may be multiple tool call, generally 2.
            // The good one is the second tool call. The first tool call is a
            // pre-call to calibrate the model json. It may be skipped for next
            // resources.
            // So use the last call, that is the good one.
            $toolCall = $choice->message->toolCalls;
            if ($toolCall) {
                $toolCall = $toolCall[array_key_last($toolCall)];
                $content = $toolCall->function->toArray();
                $content = $content['arguments'] ?? [];
                $content = empty($content) ? [] : json_decode($content, true);
            } else {
                $content = $choice->message->content;
            }
        }
        $proposal = $this->fillProposal($content, $resourceTemplate);

        // Validate the generated resource.
        /** @see \Contribute\Controller\Site\ContributionController::submitAction() */

        // Store the generated resource.
        $data = [
            'o:resource' => ['o:id' => $resource->id()],
            'o:owner' => ['o:id' => $user->getId()],
            'o:model' => $model,
            'o:response_id' => (string) $response->id,
            'o:tokens_input' => $response->usage?->promptTokens ?? 0,
            'o:tokens_output' => $response->usage?->completionTokens ?? 0,
            'o:reviewed' => false,
            'o:proposal' => $proposal,
        ];

        try {
            $aiRecord = $this->api->create('ai_records', $data)->getContent();
        } catch (\Exception $e) {
            $this->skipMessenger || $this->messenger->addError(new PsrMessage(
                'An exception occurred when creating ai record: {msg}', // @translate
                ['msg' => $e->getMessage()]
            ));
            $this->logger->err(
                '[AiGenerator] Exception when creating ai record for resource #{resource_id}: {msg}', // @translate
                ['resource_id' => $resource->id(), 'msg' => $e->getMessage()]
            );
            return null;
        }

        if ($validate
            && $this->acl->userIsAllowed(\AiGenerator\Entity\AiRecord::class, 'update')
        ) {
            $resourceData = $aiRecord->proposalToResourceData();
            if ($resourceData) {
                $errorStore = new ErrorStore();
                $this->validateRecordOrCreateOrUpdate->__invoke($aiRecord, $resourceData, $errorStore, true, false, !$this->skipMessenger);
            } else {
                $this->skipMessenger || $this->messenger->addError(new PsrMessage(
                    'AI record not valid.' // @translate
                ));
                $this->logger->err(
                    '[AiGenerator] AI record #{ai_record_id}: not valid.', // @translate
                    ['ai_record_id' => $aiRecord->id()]
                );
            }
        }

        return $aiRecord;
    }

    /**
     * Specify the format for the response.
     *
     * The precise omeka json-ld format can be created, but it is useless here,
     * because the aim is to do a proposition with human validation, not direct
     * creation of items via api.
     * Else, such a format can be used:
     * "dcterms:creator": [{"type": "literal", "property_id": "auto", "@value": "John Doe"}],
     *
     * @experimental
     */
    protected function completePrompts(
        ?string $promptSystem,
        ?string $promptUser,
        string $model,
        ItemRepresentation|MediaRepresentation $resource,
        array $urls,
    ): array {
        $use = null;
        if (empty($promptSystem) && empty($promptUser)) {
            $use = 'system';
            $prompt = $promptSystem;
        } elseif (empty($promptSystem)) {
            $use = 'user';
            $prompt = $promptUser;
        } elseif (empty($promptUser)) {
            $use = 'system';
            $prompt = $promptSystem;
        } else {
            $use = 'system';
            $prompt = $promptSystem;
        }

        if (empty($this->dataModels[$model]['separate_urls'])
            && !str_contains($promptSystem, '{url}')
            && !str_contains($promptUser, '{url}')
            && !str_contains($promptSystem, '{urls}')
            && !str_contains($promptUser, '{urls}')
        ) {
            // Completions does not allow message, so add urls.
            $prompt .= "\n"
                . $this->translator->translate('Urls of images to analyze are:') // @translate
                . "\n"
                . implode(",\n", $urls);
        }

        if (!str_contains($promptSystem, 'json') && !str_contains($promptUser, 'json')) {
            $prompt .= "\n"
                . $this->translator->translate(
                    'Output the response as JSON object. Each property may be single or multiple. Return only requested properties. Skip empty values.' // @translate
                );
        }

        $regex = [
            '{properties}',
            '{properties_name}',
            '{properties_sample}',
            '{properties_sample_json}',
        ];
        $regex = '~' . implode('|', array_map(fn ($v) => preg_quote($v, '~'), $regex)) . '~';
        if (!preg_match($regex, $promptSystem) && !preg_match($regex, $promptUser)) {
            // Completions does not allow message, so add urls.
            $prompt .= "\n"
                . $this->translator->translate('Example:') // @translate
                . "\n"
                . '{properties_sample}';
        }

        return $use === 'user'
            ? [$promptSystem, $prompt]
            : [$prompt, $promptUser];
    }

    /**
     * Prepare a prompt with placeholders.
     *
     * The name placeholders are experimental.
     */
    protected function preparePrompt(ItemRepresentation|MediaRepresentation $resource, ?string $prompt, array $urls): ?string
    {
        $replace = [];

        $prompt = (string) $prompt;

        if (str_contains($prompt, '{url}')) {
            $replace['{url}'] = reset($urls);
        }

        if (str_contains($prompt, '{urls}')) {
            $replace['{urls}'] = implode(', ', $urls);
        }

        $missingTemplate = function (string $placeholder): null {
            $this->skipMessenger || $this->messenger->addWarning(new PsrMessage(
                'The prompt contains the placeholder "{placeholder}", but there is no template to replace it or it is marked non-generatable.', // @translate
                ['placeholder' => $placeholder]
            ));
            $this->logger->warn(
                '[AiGenerator] The prompt contains the placeholder "{placeholder}", but there is no template to replace it or it is marked non-generatable.', // @translate
                ['placeholder' => $placeholder]
            );
            return null;
        };

        /** @see GenerativeData() */
        /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template */
        $template = $resource->resourceTemplate();
        if ($template) {
            $templateGeneratable = $template->dataValue('generatable');
            $templateGeneratable = in_array($templateGeneratable, ['specific', 'none'])
                ? $templateGeneratable
                : 'all';
        } else {
            $templateGeneratable = 'none';
        }

        if (str_contains($prompt, '{properties}')) {
            if ($template && $templateGeneratable !== 'none') {
                $list = [];
                foreach ($template->resourceTemplateProperties() as $rtp) {
                    if ($templateGeneratable || $rtp->dataValue('generatable')) {
                        $list[] = $rtp->property()->term();
                    }
                }
                $replace['{properties}'] = implode(', ', $list);
            } else {
                $missingTemplate('{properties}');
                $replace['{properties}'] = '';
            }
        }

        if (str_contains($prompt, '{properties_names}')) {
            if ($template && $templateGeneratable !== 'none') {
                $list = [];
                foreach ($template->resourceTemplateProperties() as $rtp) {
                    if ($templateGeneratable || $rtp->dataValue('generatable')) {
                        $property = $rtp->property();
                        $list[] = sprintf(
                            '%1$s: %2$s',
                            $this->translator->translate($property->vocabulary()->label()),
                            $this->translator->translate($property->label())
                        );
                    }
                }
                $replace['{properties_names}'] = implode(', ', $list);
            } else {
                $missingTemplate('{properties_names}');
                $replace['{properties_names}'] = '';
            }
        }

        if (str_contains($prompt, '{properties_sample}')) {
            if ($template && $templateGeneratable !== 'none') {
                $list = [];
                foreach ($template->resourceTemplateProperties() as $rtp) {
                    if ($templateGeneratable || $rtp->dataValue('generatable')) {
                        $property = $rtp->property();
                        $list[$property->term()] = $this->translator->translate($property->label());
                    }
                }
                $replace['{properties_sample}'] =  json_encode($list, 448);
            } else {
                $missingTemplate('{properties_sample}');
                $replace['{properties_sample}'] = '';
            }
        }

        if (str_contains($prompt, '{properties_sample_json}')) {
            if ($template && $templateGeneratable !== 'none') {
                $list = [];
                foreach ($template->resourceTemplateProperties() as $rtp) {
                    if ($templateGeneratable || $rtp->dataValue('generatable')) {
                        $property = $rtp->property();
                        $list[$property->term()] = $this->translator->translate($property->label());
                    }
                }
                $replace['{properties_sample_json}'] = '```json' . "\n"
                    . json_encode($list, 448)
                    . '```';
            } else {
                $missingTemplate('{properties_sample_json}');
                $replace['{properties_sample_json}'] = '';
            }
        }

        return $replace
            ? strtr($prompt, $replace)
            : $prompt;
    }

    /**
     * Specify the format for the response.
     *
     * The precise omeka json-ld format can be created, but it is useless here,
     * because the aim is to do a proposition with human validation, not direct
     * creation of items via api.
     * Else, such a format can be used:
     * "dcterms:creator": [{"type": "literal", "property_id": "auto", "@value": "John Doe"}],
     */
    protected function prepareTools(ItemRepresentation|MediaRepresentation $resource): ?array
    {
        /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template */
        $template = $resource->resourceTemplate();
        if ($template) {
            $templateGeneratable = $template->dataValue('generatable');
            $templateGeneratable = in_array($templateGeneratable, ['specific', 'none'])
                ? $templateGeneratable
                : 'all';
        } else {
            $templateGeneratable = 'none';
        }

        if (!$template || $templateGeneratable === 'none') {
            $this->skipMessenger || $this->messenger->addWarning(new PsrMessage(
                'The process requires a resource with a template with generatable properties.' // @translate
            ));
            $this->logger->warn(
                '[AiGenerator] For resource #{resource_id}, the process requires a resource with a template with generatable properties.', // @translate
                ['resource_id' => $resource->id()]
            );
            return null;
        }

        /** @see https://packagist.org/packages/openai-php/client */

        $tool = [
            'type' => 'function',
            'function' => [
                'name' => 'proposed_record',
                'description' => $this->translator->translate('Get specific metadata from an image.'), // @translate
                'parameters' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
            ],
        ];

        foreach ($template->resourceTemplateProperties() as $rtp) {
            if ($templateGeneratable || $rtp->dataValue('generatable')) {
                $property = $rtp->property();
                $term = $property->term();
                $tool['function']['parameters']['properties'][$term] = [
                    'type' => 'string',
                    'description' => sprintf(
                        '%1$s: %2$s',
                        $this->translator->translate($property->label()),
                        $this->translator->translate($property->comment())
                    ),
                    // TODO For custom vocabs, the values may be predefined with "enum".
                    // TODO Use the data type for numeric and dates.
                ];
                /* // TODO Manage property "required": it requires a specific options in generatable.
                if ($rtp->isRequired()) {
                    $function['parameters']['required'][] = $term;
                }
                */
            }
        }

        return [$tool];
    }

    protected function fillProposal(array|string|null $content, ?ResourceTemplateRepresentation $resourceTemplate): array
    {
        if (in_array($content, [null, '', []], true)) {
            return [];
        }

        $proposal = [
            'template' =>  $resourceTemplate ? $resourceTemplate->id() : null,
        ];

        // Some models don't support structured output, so manage all cases.

        // Check if the content is json encoded.
        if (!is_array($content)) {
            $metadata = @json_decode($content, true);
            if (is_array($metadata)) {
                $content = $metadata;
            }
        }

        if (!is_array($content)) {
            // Use the default format, that is wrapped with markdown ```json```.
            $matches = [];
            if (!preg_match('~```json\s*(?<json>.*)\s*```~s', $content, $matches)) {
                $proposal['curation:data'] = [
                    [
                        'proposed' => [
                            '@value' => $content,
                        ],
                    ],
                ];
                return $proposal;
            }

            $content = json_decode($matches['json'], true);
            if (!is_array($content)) {
                $proposal['curation:data'] = [
                    [
                        'proposed' => [
                            '@value' => $matches['json'],
                        ],
                    ],
                ];
                return $proposal;
            }
        }

        // Take care of various json output formats.
        $metadata = $content['data'] ?? $content['arguments'] ?? $content;

        foreach ($metadata as $key => $value) {
            // Manage multiple values for the same property, if supported.
            if (!is_array($value)) {
                $value = [$value];
            }
            foreach ($value as $val) {
                $proposal[$key] = [
                    [
                        'proposed' => [
                            '@value' => $val,
                        ],
                    ],
                ];
            }
        }

        return $proposal;
    }

    protected function urlOrBase64(MediaRepresentation $media, string $derivative): string
    {
        $url = $derivative === 'original'
            ? $media->originalUrl()
            : $media->thumbnailUrl($derivative);
        if (!$this->isUrlLocal($url)) {
            return $url;
        }
        $content = (string) @file_get_contents($url);
        return sprintf('data:%1$s;base64,%2$s', $media->mediaType(), base64_encode($content));
    }

    protected function isUrlLocal(string $url): bool
    {
        $parsedUrl = parse_url($url);

        // Manage local files.
        if (!isset($parsedUrl['host'])) {
            return true;
        }

        $host = $parsedUrl['host'];
        $ip = gethostbyname($host);

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }

        return $ip === '::1'
            || $ip === '127.0.0.1'
            || preg_match('/^10\./', $ip)
            || preg_match('/^192\.168\./', $ip)
            || preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip);
    }
}
