<?php

declare(strict_types=1);

namespace AiGenerator\View\Helper;

use AiGenerator\Api\Representation\AiRecordRepresentation;
use AiGenerator\Mvc\Controller\Plugin\GenerativeData;
use Common\Stdlib\EasyMeta;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ResourceTemplateRepresentation;

class AiRecordFields extends AbstractHelper
{
    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Common\Stdlib\EasyMeta
     */
    protected $easyMeta;

    /**
     * @var GenerativeData
     */
    protected $generativeData;

    /**
     * @var bool
     */
    protected $hasAdvancedTemplate;

    /**
     * @var bool
     */
    protected $hasCustomVocab;

    /**
     * @var bool
     */
    protected $hasNumericDataTypes;

    /**
     * @var bool
     */
    protected $hasValueSuggest;

    public function __construct(
        ApiManager $api,
        GenerativeData $generativeData,
        EasyMeta $easyMeta,
        bool $hasAdvancedTemplate,
        bool $hasCustomVocab,
        bool $hasNumericDataTypes,
        bool $hasValueSuggest
    ) {
        $this->api = $api;
        $this->generativeData = $generativeData;
        $this->easyMeta = $easyMeta;
        $this->hasAdvancedTemplate = $hasAdvancedTemplate;
        $this->hasCustomVocab = $hasCustomVocab;
        $this->hasNumericDataTypes = $hasNumericDataTypes;
        $this->hasValueSuggest = $hasValueSuggest;
    }

    /**
     * Get all fields for this resource, updatable or not.
     *
     * The format is the same than the module Contribution in order to simplify
     * the maintenance, even if with this module, it can be simplified, because
     * the proposal are always scalar.
     *
     * The order is the one of the resource template.
     *
     * Some generations may not have the matching fields: it means that the
     * config changed, so the values are no more editable or fillable.
     * Unlike Contribution, these fields are displayed when the property exists.
     *
     * The output is similar than $resource->values(), but may contain empty
     * properties, and four more keys, editable, fillable, data types and
     * generations.
     *
     * Note that sub-generation fields for media are not included here.
     *
     * The key "original" is optional.
     * "updatable" is "editable" in Contribution (for a future version).
     * "generatable" is "fillable" in Contribution.
     *
     * The minimum number of generations is managed: empty generations may
     * be added according to the minimal number of values.
     *
     * <code>
     * [
     *   {term} => [
     *     'template_property' => {ResourceTemplatePropertyRepresentation|null},
     *     'property' => {PropertyRepresentation},
     *     'alternate_label' => {label},
     *     'alternate_comment' => {comment},
     *     'required' => {bool},
     *     'min_values' => {int},
     *     'max_values' => {int},
     *     'more_values' => {int},
     *     'editable' => {bool},
     *     'fillable' => {bool},
     *     'datatypes' => {array},
     *     'values' => [
     *       {ValueRepresentation}, …
     *     ],
     *     'ai_records' => [
     *       [
     *         'type' => {string},
     *         'basetype' => {string}, // To make process easier (literal, resource or uri).
     *         'new' => {bool}, // Is a new value (edited/filled by user or missing value).
     *         'empty' => {bool}, // No generation or removed value.
     *         'original' => [
     *           'value' => {ValueRepresentation},
     *           '@value' => {string},
     *           '@resource' => {int},
     *           '@uri' => {string},
     *           '@label' => {string},
     *         ],
     *         'proposed' => [
     *           'store' => {string}, // Path where a file is stored (for media only).
     *           '@value' => {string},
     *           '@resource' => {int},
     *           '@uri' => {string},
     *           '@label' => {string},
     *         ],
     *       ], …
     *     ],
     *   ],
     * ]
     * </code>
     *
     * @todo Remove the "@" in proposition values (or build a class).
     * @todo Store language.
     *
     * @todo Factorize with \AiGenerator\Site\GenerationController::prepareProposal()
     * @todo Factorize with \AiGenerator\Api\Representation\GenerationRepresentation::proposalNormalizeForValidation()
     * @todo Factorize with \AiGenerator\Api\Representation\GenerationRepresentation::proposalToResourceData()
     *
     * @todo Simplify when the status "is patch" or "new resource" (at least remove all original data).
     *
     * @var bool $isSubTemplate Allow to check the good allowed template via
     *   generativeData(), so the allowed resource templates or allowed
     *   resource templages for media). No other difference, so invoke the right
     *   resource, the right generation part, or the right template when
     *   needed.
     */
    public function __invoke(
        ?AbstractResourceEntityRepresentation $resource = null,
        ?AiRecordRepresentation $aiRecord = null,
        ?ResourceTemplateRepresentation $resourceTemplate = null,
        ?bool $isSubTemplate = false,
        ?int $indexProposalMedia = null
    ): array {
        $fields = [];

        $isSubTemplate = (bool) $isSubTemplate;
        $defaultField = [
            'template_property' => null,
            'property' => null,
            'alternate_label' => null,
            'alternate_comment' => null,
            'required' => false,
            'min_values' => 0,
            'max_values' => 0,
            'more_values' => false,
            // "generatable" is "fillable" in Contribution. "updatable" is "editable" (currently not used).
            'editable' => false,
            'fillable' => false,
            'datatypes' => [],
            'values' => [],
            'ai_records' => [],
        ];

        // The generation is always on the stored resource, if any.
        $values = [];
        if ($aiRecord) {
            $resource = $aiRecord->resource();
            if ($resource) {
                $values = $resource->values();
                if (!$isSubTemplate) {
                    $resourceTemplate = $resource->resourceTemplate();
                }
            }
            if (!$isSubTemplate) {
                $resourceTemplate = $aiRecord->resourceTemplate();
            }
        } elseif ($resource) {
            $resourceTemplate = $resource->resourceTemplate();
            $values = $resource->values();
        }

        $generative = clone $this->generativeData;
        $generative = $generative->__invoke($resourceTemplate, $isSubTemplate);
        $resourceTemplate = $generative->template();

        // TODO Currently, only new media are managed as sub-resource: generation for new resource, not generation for existing item with media at the same time.
        if ($isSubTemplate) {
            $values = [];
        }

        // List the fields for the resource.
        foreach ($resourceTemplate ? $resourceTemplate->resourceTemplateProperties() : [] as $templateProperty) {
            $property = $templateProperty->property();
            $term = $property->term();
            if ($this->hasAdvancedTemplate) {
                /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyRepresentation $templateProperty */
                $minValues = (int) $templateProperty->mainDataValue('min_values');
                $maxValues = (int) $templateProperty->mainDataValue('max_values');
            } else {
                $minValues = 0;
                $maxValues = 0;
            }
            $valuesValues = $values[$term]['values'] ?? [];
            $fields[$term] = [
                'template_property' => $templateProperty,
                'property' => $property,
                'alternate_label' => $templateProperty->alternateLabel(),
                'alternate_comment' => $templateProperty->alternateComment(),
                'required' => $templateProperty->isRequired(),
                'min_values' => $minValues,
                'max_values' => $maxValues,
                'more_values' => $maxValues && count($valuesValues) < $maxValues,
                'editable' => $generative->isTermEditable($term),
                'fillable' => $generative->isTermFillable($term),
                'datatypes' => $generative->dataTypeTerm($term),
                'values' => $valuesValues,
                'ai_records' => [],
            ];
        }

        // The remaining values don't have a template and are never editable
        // or fillable. Nevertheless, they are displayed.
        foreach ($values as $term => $valueInfo) {
            if (!isset($fields[$term])) {
                // Value info includes the property and the values.
                $fields[$term] = $valueInfo;
                $fields[$term]['required'] = false;
                $fields[$term]['min_values'] = 0;
                $fields[$term]['max_values'] = 0;
                $fields[$term]['more_values'] = false;
                $fields[$term]['editable'] = false;
                $fields[$term]['fillable'] = false;
                $fields[$term]['datatypes'] = [];
                $fields[$term]['ai_records'] = [];
                $fields[$term] = array_replace($defaultField, $fields[$term]);
            }
        }

        // The template is required.
        if (!$resourceTemplate || !$generative || !$generative->isGenerative()) {
            return $fields;
        }

        // Initialize generations with existing values, then append generations.

        foreach ($fields as $term => $field) {
            if ($term === 'file') {
                continue;
            }
            /** @var \Omeka\Api\Representation\ValueRepresentation $value */
            foreach ($field['values'] as $value) {
                // Method value() is label or value depending on type.
                $dataType = $value->type();
                $baseType = $this->easyMeta->dataTypeMain($dataType);
                // TODO No need to check if the data type is managed?
                if ($baseType === 'uri') {
                    $baseType = 'uri';
                    $val = null;
                    $res = null;
                    $uri = $value->uri();
                    $label = $value->value();
                } elseif ($baseType === 'resource') {
                    $baseType = 'resource';
                    $vr = $value->valueResource();
                    $val = null;
                    $res = $vr ? $vr->id() : null;
                    $uri = null;
                    $label = null;
                } else {
                    $baseType = 'literal';
                    $val = $value->value();
                    $res = null;
                    $uri = null;
                    $label = null;
                }
                $fields[$term]['ai_records'][] = [
                    // The type cannot be changed.
                    'type' => $dataType,
                    'basetype' => $baseType,
                    'new' => false,
                    'empty' => true,
                    'original' => [
                        'value' => $value,
                        '@value' => $val,
                        '@resource' => $res,
                        '@uri' => $uri,
                        '@label' => $label,
                    ],
                    'proposed' => [
                        '@value' => null,
                        '@resource' => null,
                        '@uri' => null,
                        '@label' => null,
                    ],
                ];
            }
        }

        if (!$aiRecord) {
            return $this->finalize($fields);
        }

        $proposals = $aiRecord->proposal();
        if (is_int($indexProposalMedia)) {
            $proposals = $proposals['media'][$indexProposalMedia] ?? [];
        }

        // Clean data for the special keys.
        unset($proposals['template'], $proposals['media']);

        foreach ($proposals as $term => $termProposal) {
            if (!is_array($termProposal)) {
                // Data "migrated" = true can be stored here.
                continue;
            }
            foreach ($termProposal as $key => $proposal) {
                if (isset($proposal['proposed']['@uri'])) {
                    $proposal['original']['@uri'] = $this->cleanString($proposal['original']['@uri'] ?? '');
                    $proposal['original']['@label'] = $this->cleanString($proposal['original']['@label'] ?? '');
                    if (($proposal['original']['@uri'] === '' && $proposal['proposed']['@uri'] === '')
                        && ($proposal['original']['@label'] === '' && $proposal['proposed']['@label'] === '')
                    ) {
                        unset($proposals[$term][$key]);
                    }
                } elseif (isset($proposal['proposed']['@resource'])) {
                    $proposal['original']['@resource'] = (int) $proposal['original']['@resource'] ?? 0;
                    if (!$proposal['original']['@resource'] && !$proposal['proposed']['@resource']) {
                        unset($proposals[$term][$key]);
                    }
                } else {
                    $proposal['original']['@value'] = $this->cleanString($proposal['original']['@value'] ?? '');
                    if ($proposal['original']['@value'] === '' && $proposal['proposed']['@value'] === '') {
                        unset($proposals[$term][$key]);
                    }
                }
            }
        }

        $proposals = array_filter($proposals);
        if (!count($proposals)) {
            return $this->finalize($fields);
        }

        // File is specific: for media only, one value only, not updatable,
        // not a property and not in resource template.
        if (isset($proposals['file'][0]['proposed']['@value']) && $proposals['file'][0]['proposed']['@value'] !== '') {
            // Fill the file first to keep it first.
            $fields = array_merge(['file' => []], $fields);
            $fields['file'] = [
                'template_property' => null,
                'property' => null,
                'alternate_label' => $this->getView()->translate('File'),
                'alternate_comment' => null,
                'required' => true,
                'min_values' => 1,
                'max_values' => 1,
                'more_values' => false,
                'editable' => false,
                'fillable' => true,
                'datatypes' => ['file'],
                'values' => [],
                'ai_records' => [],
            ];
            $fields['file']['ai_records'][] = [
                'type' => 'file',
                'basetype' => 'literal',
                'lang' => null,
                'new' => true,
                'empty' => empty($proposals['file'][0]['proposed']['store']),
                'original' => [
                    'value' => null,
                    '@resource' => null,
                    '@value' => null,
                    '@uri' => null,
                    '@label' => null,
                ],
                'proposed' => [
                    'store' => $proposals['file'][0]['proposed']['store'] ?? null,
                    '@value' => $proposals['file'][0]['proposed']['@value'],
                    '@resource' => null,
                    '@uri' => null,
                    '@label' => null,
                ],
            ];
        }

        // Fill the proposed generations, according to the original value.
        foreach ($fields as $term => &$field) {
            if ($term === 'file') {
                continue;
            }
            if (!isset($proposals[$term])) {
                continue;
            }
            foreach ($field['ai_records'] as &$fieldGeneration) {
                $proposed = null;
                $dataType = $fieldGeneration['type'];
                if (!$generative->isTermDataType($term, $dataType)) {
                    continue;
                }
                $baseType = $this->easyMeta->dataTypeMain($dataType);
                if ($baseType === 'uri') {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        // For the customvocab, the label is static, so use the
                        // original one, but here the label is already checked.
                        if (isset($proposal['original']['@uri'])
                            && $proposal['original']['@uri'] === $fieldGeneration['original']['@uri']
                            && $proposal['original']['@label'] === $fieldGeneration['original']['@label']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldGeneration['empty'] = false;
                    $fieldGeneration['proposed'] = [
                        '@value' => null,
                        '@resource' => null,
                        '@uri' => $proposed['@uri'],
                        '@label' => $proposed['@label'],
                    ];
                } elseif ($baseType === 'resource') {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['original']['@resource'])
                            && (int) $proposal['original']['@resource']
                            && $proposal['original']['@resource'] === $fieldGeneration['original']['@resource']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldGeneration['empty'] = false;
                    $fieldGeneration['proposed'] = [
                        '@value' => null,
                        '@resource' => (int) $proposed['@resource'],
                        '@uri' => null,
                        '@label' => null,
                    ];
                } else {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['original']['@value'])
                            && $proposal['original']['@value'] === $fieldGeneration['original']['@value']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldGeneration['empty'] = false;
                    $fieldGeneration['proposed'] = [
                        '@value' => $proposed['@value'],
                        '@resource' => null,
                        '@uri' => null,
                        '@label' => null,
                    ];
                }
                unset($proposals[$term][$keyProposal]);
            }
        }
        unset($field, $fieldGeneration);

        // Fill the proposed generate, according to the existing values: some
        // generations may have been accepted or the resource updated, so check
        // if there are remaining generations that were validated.
        foreach ($fields as $term => &$field) {
            if ($term === 'file') {
                continue;
            }
            if (!isset($proposals[$term])) {
                continue;
            }
            foreach ($field['ai_records'] as &$fieldGeneration) {
                $proposed = null;
                $dataType = $fieldGeneration['type'];
                if (!$generative->isTermDatatype($term, $dataType)) {
                    continue;
                }
                $baseType = $this->easyMeta->dataTypeMain($dataType);
                if ($baseType === 'uri') {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['proposed']['@uri'])
                            && $proposal['proposed']['@uri'] === $fieldGeneration['original']['@uri']
                            && $proposal['proposed']['@label'] === $fieldGeneration['original']['@label']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldGeneration['empty'] = false;
                    $fieldGeneration['proposed'] = [
                        '@value' => null,
                        '@resource' => null,
                        '@uri' => $proposed['@uri'],
                        '@label' => $proposed['@label'],
                    ];
                } elseif ($baseType === 'resource') {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['proposed']['@resource'])
                            && (int) $proposal['proposed']['@resource']
                            && $proposal['proposed']['@resource'] === $fieldGeneration['original']['@resource']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldGeneration['empty'] = false;
                    $fieldGeneration['proposed'] = [
                        '@value' => null,
                        '@resource' => (int) $proposed['@resource'],
                        '@uri' => null,
                        '@label' => null,
                    ];
                } else {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['proposed']['@value'])
                            && $proposal['proposed']['@value'] === $fieldGeneration['original']['@value']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldGeneration['empty'] = false;
                    $fieldGeneration['proposed'] = [
                        '@value' => $proposed['@value'],
                        '@resource' => null,
                        '@uri' => null,
                        '@label' => null,
                    ];
                }
                unset($proposals[$term][$keyProposal]);
            }
        }
        unset($field, $fieldGeneration);

        // Append remaining proposed terms. They may be a property removed from
        // the template or from vocabularies, or from an older config.
        // Values without property are not displayed.
        // $proposals = array_intersect_key(array_filter($proposals), $generative->fillableProperties());
        foreach ($proposals as $term => $termProposal) {
            $propertyId = $this->easyMeta->propertyId($term);
            if (!$propertyId) {
                continue;
            }
            if (empty($fields[$term])) {
                $property = $this->api->read('properties', ['id' => $propertyId])->getContent();
                $fields[$term] = [
                    'template_property' => null,
                    'property' => $property,
                    'alternate_label' => null,
                    'alternate_comment' => null,
                    'required' => false,
                    'min_values' => 0,
                    'max_values' => 0,
                    'more_values' => false,
                    'editable' => false,
                    'fillable' => false,
                    'datatypes' => ['literal', 'resource', 'uri'],
                    'values' => [],
                    'ai_records' => [],
                ];
            }
            // $isFillable = $generative->isTermFillable($term);
            $typeTemplate = null;
            $resourceTemplateProperty = $resourceTemplate
                ? $resourceTemplate->resourceTemplateProperty($propertyId)
                : null;
            // TODO Check if it is possible to have a property that is not set.
            if ($resourceTemplateProperty) {
                $typeTemplate = $resourceTemplateProperty->dataType();
            }
            foreach ($termProposal as $proposal) {
                if (!empty($proposal['empty'])) {
                    continue;
                }
                if ($typeTemplate) {
                    $dataType = $typeTemplate;
                } elseif (isset($proposal['proposed']['@uri'])) {
                    $dataType = 'uri';
                } elseif (isset($proposal['proposed']['@resource'])) {
                    $dataType = 'resource';
                } else {
                    $dataType = 'literal';
                }
                /*
                if (!$generative->isTermDatatype($term, $dataType)) {
                    continue;
                }
                */
                $baseType = $this->easyMeta->dataTypeMain($dataType) ?? 'literal';
                if ($baseType === 'uri') {
                    $fields[$term]['ai_records'][] = [
                        'type' => $dataType,
                        'basetype' => 'uri',
                        'new' => true,
                        'empty' => false,
                        'original' => [
                            'value' => null,
                            '@resource' => null,
                            '@value' => null,
                            '@uri' => null,
                            '@label' => null,
                        ],
                        'proposed' => [
                            '@value' => null,
                            '@resource' => null,
                            '@uri' => $proposal['proposed']['@uri'] ?? '',
                            '@label' => $proposal['proposed']['@label'] ?? '',
                        ],
                    ];
                } elseif ($baseType === 'resource') {
                    $fields[$term]['ai_records'][] = [
                        'type' => $dataType,
                        'basetype' => 'resource',
                        'new' => true,
                        'empty' => false,
                        'original' => [
                            'value' => null,
                            '@resource' => null,
                            '@value' => null,
                            '@uri' => null,
                            '@label' => null,
                        ],
                        'proposed' => [
                            '@value' => null,
                            '@resource' => (int) ($proposal['proposed']['@resource'] ?? 0),
                            '@uri' => null,
                            '@label' => null,
                        ],
                    ];
                } else {
                    $fields[$term]['ai_records'][] = [
                        'type' => $dataType,
                        'basetype' => 'literal',
                        'new' => true,
                        'empty' => false,
                        'original' => [
                            'value' => null,
                            '@resource' => null,
                            '@value' => null,
                            '@uri' => null,
                            '@label' => null,
                        ],
                        'proposed' => [
                            '@value' => $proposal['proposed']['@value'] ?? '',
                            '@resource' => null,
                            '@uri' => null,
                            '@label' => null,
                        ],
                    ];
                }
            }
        }

        return $this->finalize($fields);
    }

    /**
     * Finalize: remove invalid generations and add empty ones when needed.
     */
    protected function finalize(array $fields): array
    {
        foreach ($fields as $term => &$field) {
            if ($term === 'file') {
                continue;
            }
            // Remove generations with an invalid or an unavailable type.
            // This is a security fix, but it can remove data.
            foreach ($field['ai_records'] as $key => &$fieldGeneration) {
                $dataType = $fieldGeneration['type'] ?? '';
                $typeColon = strtok($dataType, ':');
                $baseType = $this->easyMeta->datatypeMain($dataType) ?? 'literal';
                // FIXME Warning, numeric:interval and numeric:duration are not managed.
                if (!$this->hasNumericDataTypes && $typeColon === 'numeric') {
                    unset($field['ai_records'][$key]);
                    continue;
                }
                if (!$this->hasCustomVocab && $typeColon === 'customvocab') {
                    unset($field['ai_records'][$key]);
                    continue;
                }
                if (!$this->hasValueSuggest && ($typeColon === 'valuesuggest' || $typeColon === 'valuesuggestall')) {
                    unset($field['ai_records'][$key]);
                    continue;
                }
            }
            unset($fieldGeneration);

            // Clean indexes for old generations.
            $field['ai_records'] = array_values($field['ai_records']);
            if (!$field['fillable']) {
                continue;
            }

            // The minimum is 1 when a value is required.
            $minValues = (int) $field['min_values'] ?: (int) $field['required'];
            $maxValues = (int) $field['max_values'];
            if (!$minValues && !$maxValues) {
                $field['more_values'] = true;
                continue;
            }

            // If editable, values and generations are a single list, else
            // they are combined.
            // TODO Check for correction, with some values corrected and some appended.
            $countValues = count($field['values']);
            $countGenerations = count($field['ai_records']);
            $countExisting = $field['editable']
                ? max($countValues, $countGenerations)
                : $countValues + $countGenerations;
            $missingValues = $minValues && $minValues > $countExisting
                ? $minValues - $countExisting
                : 0;
            // The button is always added, and managed by js anyway, because the
            // button should be available when a value is removed.
            $field['more_values'] = !$maxValues
                || $maxValues < $missingValues;

            $dataType = reset($field['datatypes']);
            $baseType = $this->easyMeta->dataTypeMain($dataType) ?? 'literal';
            // Prepare empty generations to simplify theme.
            while ($missingValues) {
                $field['ai_records'][] = [
                    'type' => $dataType,
                    'basetype' => $baseType,
                    'new' => true,
                    'empty' => true,
                    'original' => [
                        'value' => null,
                        '@value' => null,
                    ],
                    'proposed' => [
                        '@value' => null,
                    ],
                ];
                --$missingValues;
            }
        }
        return $fields;
    }

    /**
     * Trim and normalize end of lines of a string.
     */
    protected function cleanString($string): string
    {
        return strtr(trim((string) $string), ["\r\n" => "\n", "\n\r" => "\n", "\r" => "\n"]);
    }
}
