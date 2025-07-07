<?php

declare(strict_types=1);

namespace AiGenerator\Api\Adapter;

use Doctrine\ORM\Query\Expr\Comparison;
use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class AiRecordAdapter extends AbstractEntityAdapter
{
    protected $sortFields = [
        'id' => 'id',
        'resource' => 'resource',
        'owner' => 'owner',
        'model' => 'model',
        'responseid' => 'responseid',
        'tokens_input' => 'tokensInput',
        'tokens_output' => 'tokensOutput',
        'reviewed' => 'reviewed',
        'created' => 'created',
        'modified' => 'modified',
    ];

    protected $scalarFields = [
        'id' => 'id',
        'resource' => 'resource',
        'owner' => 'owner',
        'model' => 'model',
        'responseid' => 'responseid',
        'tokens_input' => 'tokensInput',
        'tokens_output' => 'tokensOutput',
        'reviewed' => 'reviewed',
        'proposal' => 'proposal',
        'created' => 'created',
        'modified' => 'modified',
    ];

    public function getResourceName()
    {
        return 'ai_records';
    }

    public function getRepresentationClass()
    {
        return \AiGenerator\Api\Representation\AiRecordRepresentation::class;
    }

    public function getEntityClass()
    {
        return \AiGenerator\Entity\AiRecord::class;
    }

    public function buildQuery(QueryBuilder $qb, array $query): void
    {
        $expr = $qb->expr();

        if (isset($query['resource_id'])
            && $query['resource_id'] !== ''
            && $query['resource_id'] !== []
        ) {
            if (!is_array($query['resource_id'])) {
                $query['resource_id'] = [$query['resource_id']];
            }
            $resourceAlias = $this->createAlias();
            $qb->innerJoin(
                'omeka_root.resource',
                $resourceAlias
            );
            $qb->andWhere($expr->in(
                $resourceAlias . '.id',
                $this->createNamedParameter($qb, $query['resource_id'])
            ));
        }

        if (isset($query['owner_id'])
            && $query['owner_id'] !== ''
            && $query['owner_id'] !== []
        ) {
            if (!is_array($query['owner_id'])) {
                $query['owner_id'] = [$query['owner_id']];
            }
            $userAlias = $this->createAlias();
            $qb->innerJoin(
                'omeka_root.owner',
                $userAlias
            );
            $qb->andWhere($expr->in(
                "$userAlias.id",
                $this->createNamedParameter($qb, $query['owner_id']))
            );
        }

        if (isset($query['model'])
            && $query['model'] !== ''
            && $query['model'] !== []
        ) {
            if (!is_array($query['model'])) {
                $query['model'] = [$query['model']];
            }
            $qb->andWhere($expr->in(
                'omeka_root.model',
                $this->createNamedParameter($qb, $query['model'])
            ));
        }

        if (isset($query['responseid'])
            && $query['responseid'] !== ''
            && $query['responseid'] !== []
        ) {
            if (!is_array($query['responseid'])) {
                $query['responseid'] = [$query['responseid']];
            }
            $qb->andWhere($expr->in(
                'omeka_root.responseid',
                $this->createNamedParameter($qb, $query['responseid'])
            ));
        }

        if (isset($query['reviewed'])
            && (is_numeric($query['reviewed']) || is_bool($query['reviewed']))
        ) {
            $qb->andWhere($expr->eq(
                'omeka_root.reviewed',
                $this->createNamedParameter($qb, (bool) $query['reviewed'])
            ));
        }

        // TODO Add time comparison (see modules AdvancedSearch or Log).
        if (isset($query['created']) && $query['created'] !== '') {
            $this->buildQueryDateComparison($qb, $query, $query['created'], 'created');
        }

        if (isset($query['modified']) && $query['modified'] !== '') {
            $this->buildQueryDateComparison($qb, $query, $query['modified'], 'modified');
        }

        if (isset($query['resource_template_id'])
            && $query['resource_template_id'] !== ''
            && $query['resource_template_id'] !== []
        ) {
            $ids = $query['resource_template_id'];
            if (!is_array($ids)) {
                $ids = [$ids];
            }
            $ids = array_filter($ids);
            if ($ids) {
                // Not available in orm, but via direct dbal sql.
                $sql = <<<'SQL'
                    SELECT `id`
                    FROM `ai_record`
                    WHERE JSON_EXTRACT(`proposal`, "$.template") IN (:templates);
                    SQL;
                /** @var \Doctrine\DBAL\Connection $connection */
                $connection = $this->getServiceLocator()->get('Omeka\Connection');
                $generationIds = $connection->executeQuery($sql, ['templates' => $ids], ['templates' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY])->fetchFirstColumn();
                $generationIds = array_map('intval', $generationIds);
                if ($generationIds) {
                    $qb->andWhere($expr->in(
                        'omeka_root.id',
                        $this->createNamedParameter($qb, $generationIds)
                    ));
                } else {
                    $qb->andWhere($expr->eq(
                        'omeka_root.id',
                        $this->createNamedParameter($qb, 0)
                    ));
                }
            }
        }

        /** @experimental */
        if (isset($query['property']) && $query['property'] !== '' && $query['property'] !== []) {
            foreach ($query['property'] as $propertyData) {
                $property = $propertyData['property'] ?? null;
                if (is_null($property) || !preg_match('~^[\w-]+\:[\w-]+$~i', $property)) {
                    $qb->andWhere($expr->eq(
                        'omeka_root.id',
                        $this->createNamedParameter($qb, 0)
                    ));
                } else {
                    $type = $propertyData['type'] ?? 'eq';
                    $types = [
                        'eq' => '@value',
                        'res' => '@resource',
                    ];
                    $keyType = $types[$type] ?? '@value';
                    $text = $propertyData['text'] ?? null;
                    // Not available in orm, but via direct dbal sql.
                    $sql = <<<SQL
                        SELECT `id`
                        FROM `ai_record`
                        WHERE JSON_EXTRACT(`proposal`, "$.{$property}[*].proposed.{$keyType}") IN (:values);
                        SQL;
                    /** @var \Doctrine\DBAL\Connection $connection */
                    $text = is_array($text) ? array_values($text) : [$text];
                    foreach ($text as &$t) {
                        if ($keyType === '@resource') {
                            $t = '[' . (int) $t . ']';
                        } else {
                            $t = '[' . json_encode($t, 320) . ']';
                        }
                    }
                    unset($t);
                    $connection = $this->getServiceLocator()->get('Omeka\Connection');
                    $generationIds = $connection->executeQuery($sql, ['values' => $text], ['values' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY])->fetchFirstColumn();
                    $generationIds = array_map('intval', $generationIds);
                    if ($generationIds) {
                        $qb->andWhere($expr->in(
                            'omeka_root.id',
                            $this->createNamedParameter($qb, $generationIds)
                        ));
                    } else {
                        $qb->andWhere($expr->eq(
                            'omeka_root.id',
                            $this->createNamedParameter($qb, 0)
                        ));
                    }
                }
            }
        }

        if (isset($query['fulltext_search']) && $query['fulltext_search'] !== '') {
            $qb->andWhere($expr->like(
                'omeka_root.proposal',
                $this->createNamedParameter($qb, '%' . strtr($query['fulltext_search'], ['%' => '\%', '_' => '\_', '\\' => '\\\\']) . '%')
            ));
        }
    }

    public function preprocessBatchUpdate(array $data, Request $request)
    {
        $rawData = $request->getContent();

        if (isset($rawData['o:resource'])) {
            $data['o:resource'] = $rawData['o:resource'];
        }
        if (isset($rawData['o:owner'])) {
            $data['o:owner'] = $rawData['o:owner'];
        }
        if (isset($rawData['o:reviewed'])) {
            $data['o:reviewed'] = $rawData['o:reviewed'];
        }

        return $data;
    }

    public function validateRequest(Request $request, ErrorStore $errorStore): void
    {
        $data = $request->getContent();
        if (array_key_exists('o:proposal', $data) && !is_array($data['o:proposal'])) {
            $errorStore->addError('o:proposal', 'The proposal must be an array'); // @translate
        }
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore): void
    {
        // TODO Use shouldHydrate() and validateEntity().

        /** @var \AiGenerator\Entity\AiRecord $entity */

        $data = $request->getContent();

        $entityManager = $this->getEntityManager();

        if (Request::CREATE === $request->getOperation()) {
            $this->hydrateOwner($request, $entity);
            $resource = empty($data['o:resource']['o:id'])
                ? null
                : $entityManager->find(\Omeka\Entity\Resource::class, $data['o:resource']['o:id']);
            $reviewed = !empty($data['o:reviewed']);
            $proposal = empty($data['o:proposal']) ? [] : $data['o:proposal'];
            $entity
                ->setResource($resource)
                ->setModel($data['o:model'] ?? '')
                ->setResponseid($data['o:response_id'] ?? '')
                ->setTokensInput(empty($data['o:tokens_input']) ? 0 : (int) $data['o:tokens_input'])
                ->setTokensOutput(empty($data['o:tokens_output']) ? 0 : (int) $data['o:tokens_output'])
                ->setReviewed($reviewed)
                ->setProposal($proposal);
        } elseif (Request::UPDATE === $request->getOperation()) {
            if (!$entity->getResource() && $this->shouldHydrate($request, 'o:resource', $data)) {
                $resource = empty($data['o:resource']['o:id'])
                    ? null
                    : $entityManager->find(\Omeka\Entity\Resource::class, $data['o:resource']['o:id']);
                if ($resource) {
                    $entity
                        ->setResource($resource);
                }
            }
            if ($this->shouldHydrate($request, 'o:reviewed', $data)) {
                $reviewed = !empty($data['o:reviewed']);
                $entity
                    ->setReviewed($reviewed);
            }
            if ($this->shouldHydrate($request, 'o:proposal', $data)) {
                $proposal = empty($data['o:proposal']) ? [] : $data['o:proposal'];
                $entity
                    ->setProposal($proposal);
            }
        }

        $this->updateTimestamps($request, $entity);
    }

    /**
     * Add a comparison condition to query from a date.
     *
     * @see \Annotate\Api\Adapter\QueryDateTimeTrait::searchDateTime()
     * @see \Contribute\Api\Adapter\ContributionAdapter::buildQueryDateComparison()
     * @see \AiGenerator\Api\Adapter\AiRecordAdapter::buildQueryDateComparison()
     * @see \Log\Api\Adapter\LogAdapter::buildQueryDateComparison()
     *
     * @todo Normalize with NumericDataTypes.
     */
    protected function buildQueryDateComparison(QueryBuilder $qb, array $query, $value, $column): void
    {
        // TODO Format the date into a standard mysql datetime.
        $matches = [];
        preg_match('/^[^\d]+/', $value, $matches);
        if (!empty($matches[0])) {
            $operators = [
                '>=' => Comparison::GTE,
                '>' => Comparison::GT,
                '<' => Comparison::LT,
                '<=' => Comparison::LTE,
                '<>' => Comparison::NEQ,
                '=' => Comparison::EQ,
                'gte' => Comparison::GTE,
                'gt' => Comparison::GT,
                'lt' => Comparison::LT,
                'lte' => Comparison::LTE,
                'neq' => Comparison::NEQ,
                'eq' => Comparison::EQ,
                'ex' => 'IS NOT NULL',
                'nex' => 'IS NULL',
            ];
            $operator = trim($matches[0]);
            $operator = $operators[$operator] ?? Comparison::EQ;
            $value = mb_substr($value, mb_strlen($matches[0]));
        } else {
            $operator = Comparison::EQ;
        }
        $value = trim($value);

        // By default, sql replace missing time by 00:00:00, but this is not
        // clear for the user. And it doesn't allow partial date/time.
        // See module Advanced Search Plus.

        $expr = $qb->expr();

        // $qb->andWhere(new Comparison(
        //     $alias . '.' . $column,
        //     $operator,
        //     $this->createNamedParameter($qb, $value)
        // ));
        // return;

        $field = 'omeka_root.' . $column;
        switch ($operator) {
            case Comparison::GT:
                if (mb_strlen($value) < 19) {
                    // TODO Manage mb for substr_replace.
                    $value = substr_replace('9999-12-31 23:59:59', $value, 0, mb_strlen($value) - 19);
                }
                $param = $this->createNamedParameter($qb, $value);
                $predicateExpr = $expr->gt($field, $param);
                break;
            case Comparison::GTE:
                if (mb_strlen($value) < 19) {
                    $value = substr_replace('0000-01-01 00:00:00', $value, 0, mb_strlen($value) - 19);
                }
                $param = $this->createNamedParameter($qb, $value);
                $predicateExpr = $expr->gte($field, $param);
                break;
            case Comparison::EQ:
                if (mb_strlen($value) < 19) {
                    $valueFrom = substr_replace('0000-01-01 00:00:00', $value, 0, mb_strlen($value) - 19);
                    $valueTo = substr_replace('9999-12-31 23:59:59', $value, 0, mb_strlen($value) - 19);
                    $paramFrom = $this->createNamedParameter($qb, $valueFrom);
                    $paramTo = $this->createNamedParameter($qb, $valueTo);
                    $predicateExpr = $expr->between($field, $paramFrom, $paramTo);
                } else {
                    $param = $this->createNamedParameter($qb, $value);
                    $predicateExpr = $expr->eq($field, $param);
                }
                break;
            case Comparison::NEQ:
                if (mb_strlen($value) < 19) {
                    $valueFrom = substr_replace('0000-01-01 00:00:00', $value, 0, mb_strlen($value) - 19);
                    $valueTo = substr_replace('9999-12-31 23:59:59', $value, 0, mb_strlen($value) - 19);
                    $paramFrom = $this->createNamedParameter($qb, $valueFrom);
                    $paramTo = $this->createNamedParameter($qb, $valueTo);
                    $predicateExpr = $expr->not(
                        $expr->between($field, $paramFrom, $paramTo)
                    );
                } else {
                    $param = $this->createNamedParameter($qb, $value);
                    $predicateExpr = $expr->neq($field, $param);
                }
                break;
            case Comparison::LTE:
                if (mb_strlen($value) < 19) {
                    $value = substr_replace('9999-12-31 23:59:59', $value, 0, mb_strlen($value) - 19);
                }
                $param = $this->createNamedParameter($qb, $value);
                $predicateExpr = $expr->lte($field, $param);
                break;
            case Comparison::LT:
                if (mb_strlen($value) < 19) {
                    $value = substr_replace('0000-01-01 00:00:00', $value, 0, mb_strlen($value) - 19);
                }
                $param = $this->createNamedParameter($qb, $value);
                $predicateExpr = $expr->lt($field, $param);
                break;
            case 'IS NOT NULL':
                $predicateExpr = $expr->isNotNull($field);
                break;
            case 'IS NULL':
                $predicateExpr = $expr->isNull($field);
                break;
            default:
                return;
        }

        $qb->andWhere($predicateExpr);
    }
}
