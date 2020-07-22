<?php


namespace SilverStripe\GraphQL\Schema\DataObject;


use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\RelationList;
use SilverStripe\ORM\SS_List;
use LogicException;
use SilverStripe\ORM\UnsavedRelationList;

class FieldAccessor
{
    use Injectable;
    use Configurable;

    /**
     * @var array
     * @config
     */
    private static $allowed_aggregates = ['min', 'max', 'avg', 'count'];

    /**
     * @var callable
     * @config
     */
    private static $normaliser = 'strtolower';

    /**
     * @var array
     */
    private $lookup = [];

    /**
     * @var array
     */
    private static $__mappingCache = [];

    /**
     * @param DataObject $dataObject
     * @param string $field
     * @return string|null
     */
    public function normaliseField(DataObject $dataObject, string $field): ?string
    {
        if ($dataObject->hasField($field)) {
            return $field;
        }
        $lookup = $this->getCaseInsensitiveMapping($dataObject);

        $normalised = call_user_func_array($this->config()->get('normaliser'), [$field]);
        return $lookup[$normalised] ?? null;
    }

    /**
     * @param DataObject $dataObject
     * @param string $field
     * @return bool
     */
    public function hasField(DataObject $dataObject, string $field): bool
    {
        $path = explode('.', $field);
        return $this->normaliseField($dataObject, $path[0]) !== null;
    }

    /**
     * @param DataObject $dataObject
     * @param string $field
     * @return DBField|SS_List|DataObject|null
     */
    public function accessField(DataObject $dataObject, string $field)
    {
        if ($path = explode('.', $field)) {
            if (count($path) === 1) {
                $fieldName = $this->normaliseField($dataObject, $path[0]);
                if (!$fieldName) {
                    return null;
                }

                return $dataObject->obj($fieldName);
            }
        }

        return $this->parsePath($dataObject, $path);
    }

    /**
     * @param DataObject $dataObject
     * @param bool $includeRelations
     * @return array
     */
    public function getAllFields(DataObject $dataObject, $includeRelations = true): array
    {
        return array_map(
            $this->config()->get('normaliser'),
            array_keys($this->getCaseInsensitiveMapping($dataObject, $includeRelations))
        );
    }

    /**
     * @param DataObject $dataObject
     * @param bool $includeRelations
     * @return array
     */
    private function getAccessibleFields(DataObject $dataObject, $includeRelations = true): array
    {
        $class = get_class($dataObject);
        $schema = $dataObject->getSchema();

        $db = array_keys($schema->fieldSpecs(get_class($dataObject)));
        if (!$includeRelations) {
            return $db;
        }

        $hasOnes = array_keys(Config::forClass($class)->get('has_one'));
        $belongsTo = array_keys(Config::forClass($class)->get('belongs_to'));
        $hasMany = array_keys(Config::forClass($class)->get('has_many'));
        $manyMany = array_keys(Config::forClass($class)->get('many_many'));

        return array_merge($db, $hasOnes, $belongsTo, $hasMany, $manyMany);
    }

    /**
     * @param DataObject $dataObject
     * @param bool $includeRelations
     * @return array
     */
    private function getCaseInsensitiveMapping(DataObject $dataObject, $includeRelations = true): array
    {
        $cacheKey = get_class($dataObject) . ($includeRelations ? '_relations' : '');
        $cached = self::$__mappingCache[$cacheKey] ?? null;
        if (!$cached) {
            $normalFields = $this->getAccessibleFields($dataObject, $includeRelations);
            $lowercaseFields = array_map($this->config()->get('normaliser'), $normalFields);
            $lookup = array_combine($lowercaseFields, $normalFields);
            self::$__mappingCache[$cacheKey] = $lookup;
        }
        return self::$__mappingCache[$cacheKey];
    }

    /**
     * @param DataObject|DataList $subject
     * @param array $path
     * @return string|int|bool|array|DataList
     * @throws LogicException
     */
    private function parsePath($subject, array $path)
    {
        $nextField = array_shift($path);
        if ($subject instanceof DataObject) {
            $result = $subject->obj($nextField);
            if ($result instanceof DBField) {
                return $result->getValue();
            }
            return $this->parsePath($result, $path);
        }

        if ($subject instanceof DataList || $subject instanceof UnsavedRelationList) {
            if (!$nextField) {
                return $subject;
            }

            // Aggregate field, eg. Comments.Count(), Page.FeaturedProducts.Avg(Price)
            if (preg_match('/([A-Za-z]+)\(\s*(?:([A-Za-z_*][A-Za-z0-9_]*))?\s*\)$/', $nextField, $matches)) {
                $aggregateFunction = strtolower($matches[1]);
                $aggregateColumn = $matches[2] ?? null;
                if (!in_array($aggregateFunction, $this->config()->get('allowed_aggregates'))) {
                    throw new LogicException(sprintf(
                        'Cannot call aggregate function %s',
                        $aggregateFunction
                    ));
                }
                return call_user_func_array([$subject, $aggregateFunction], [$aggregateColumn]);
            }

            $singleton = DataObject::singleton($subject->dataClass());
            if ($singleton->hasField($nextField)) {
                return $subject->column($nextField);
            }

            $maybeList = $singleton->obj($nextField);
            if ($maybeList instanceof RelationList || $maybeList instanceof UnsavedRelationList) {
                return $this->parsePath($subject->relation($nextField), $path);
            }
        }

        throw new LogicException(sprintf(
            'Cannot resolve field %s on list of class %s',
            $nextField,
            $subject->dataClass()
        ));
    }
}