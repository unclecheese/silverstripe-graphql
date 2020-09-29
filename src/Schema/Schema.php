<?php

namespace SilverStripe\GraphQL\Schema;

use GraphQL\Type\Schema as GraphQLSchema;
use M1\Env\Exception\ParseException;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\Core\Path;
use SilverStripe\GraphQL\Dev\Benchmark;
use SilverStripe\GraphQL\Schema\Interfaces\ConfigurationApplier;
use SilverStripe\GraphQL\Schema\Exception\SchemaBuilderException;
use SilverStripe\GraphQL\Schema\Field\Field;
use SilverStripe\GraphQL\Schema\Field\ModelMutation;
use SilverStripe\GraphQL\Schema\Field\ModelQuery;
use SilverStripe\GraphQL\Schema\Field\Mutation;
use SilverStripe\GraphQL\Schema\Field\Query;
use SilverStripe\GraphQL\Schema\Interfaces\FieldPlugin;
use SilverStripe\GraphQL\Schema\Interfaces\ModelFieldPlugin;
use SilverStripe\GraphQL\Schema\Interfaces\ModelMutationPlugin;
use SilverStripe\GraphQL\Schema\Interfaces\ModelOperation;
use SilverStripe\GraphQL\Schema\Interfaces\ModelQueryPlugin;
use SilverStripe\GraphQL\Schema\Interfaces\ModelTypePlugin;
use SilverStripe\GraphQL\Schema\Interfaces\MutationPlugin;
use SilverStripe\GraphQL\Schema\Interfaces\QueryPlugin;
use SilverStripe\GraphQL\Schema\Interfaces\SchemaStorageCreator;
use SilverStripe\GraphQL\Schema\Interfaces\SchemaUpdater;
use SilverStripe\GraphQL\Schema\Interfaces\SchemaValidator;
use SilverStripe\GraphQL\Schema\Interfaces\TypePlugin;
use SilverStripe\GraphQL\Schema\Type\Enum;
use SilverStripe\GraphQL\Schema\Type\InputType;
use SilverStripe\GraphQL\Schema\Type\InterfaceType;
use SilverStripe\GraphQL\Schema\Type\ModelType;
use SilverStripe\GraphQL\Schema\Type\Scalar;
use SilverStripe\GraphQL\Schema\Type\Type;
use SilverStripe\GraphQL\Schema\Type\UnionType;
use SilverStripe\GraphQL\Schema\Interfaces\SchemaStorageInterface;
use SilverStripe\ORM\ArrayLib;
use Exception;
use SilverStripe\ORM\ArrayList;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * The main Schema definition. A docking station for all type, model, interface, etc., abstractions.
 * Applies plugins, validates, and persists to code.
 *
 */
class Schema implements ConfigurationApplier, SchemaValidator
{
    use Injectable;
    use Configurable;

    const DEFAULTS = 'defaults';
    const TYPES = 'types';
    const QUERIES = 'queries';
    const MUTATIONS = 'mutations';
    const MODELS = 'models';
    const INTERFACES = 'interfaces';
    const UNIONS = 'unions';
    const ENUMS = 'enums';
    const SCALARS = 'scalars';
    const QUERY_TYPE = 'Query';
    const MUTATION_TYPE = 'Mutation';
    const ALL = '*';

    /**
     * @var callable
     * @config
     */
    private static $pluraliser = [self::class, 'pluraliser'];

    /**
     * @var string
     */
    private $schemaKey;

    /**
     * @var array
     */
    private $defaults = [];

    /**
     * @var Type[]
     */
    private $types = [];

    /**
     * @var ModelType[]
     */
    private $models = [];

    /**
     * @var InterfaceType[]
     */
    private $interfaces = [];

    /**
     * @var UnionType[]
     */
    private $unions = [];

    /**
     * @var Enum[]
     */
    private $enums = [];

    /**
     * @var Scalar[]
     */
    private $scalars = [];

    /**
     * @var Query[]
     */
    private $queryFields = [];

    /**
     * @var Mutation[]
     */
    private $mutationFields = [];

    /**
     * @var SchemaStorageInterface
     */
    private $schemaStore;

    /**
     * Schema constructor.
     * @param string $schemaKey
     */
    public function __construct(string $schemaKey)
    {
        $this->setSchemaKey($schemaKey);
        $store = Injector::inst()->get(SchemaStorageCreator::class)
            ->createStore($schemaKey);

        $this->setStore($store);
    }

    /**
     * @param array $schemaConfig
     * @return Schema
     * @throws SchemaBuilderException
     */
    public function applyConfig(array $schemaConfig): Schema
    {
        Benchmark::start('apply-config');
        $defaults = $schemaConfig[self::DEFAULTS] ?? [];
        $types = $schemaConfig[self::TYPES] ?? [];
        $queries = $schemaConfig[self::QUERIES] ?? [];
        $mutations = $schemaConfig[self::MUTATIONS] ?? [];
        $interfaces = $schemaConfig[self::INTERFACES] ?? [];
        $unions = $schemaConfig[self::UNIONS] ?? [];
        $models = $schemaConfig[self::MODELS] ?? [];
        $enums = $schemaConfig[self::ENUMS] ?? [];
        $scalars = $schemaConfig[self::SCALARS] ?? [];

        $this->defaults = $defaults;

        static::assertValidConfig($types);
        foreach ($types as $typeName => $typeConfig) {
            static::assertValidName($typeName);
            $input = $typeConfig['input'] ?? false;
            unset($typeConfig['input']);
            $type = $input
                ? InputType::create($typeName, $typeConfig)
                : Type::create($typeName, $typeConfig);
            $this->addType($type);
        }

        static::assertValidConfig($queries);
        foreach ($queries as $queryName => $queryConfig) {
            $query = Query::create($queryName, $queryConfig);
            $this->queryFields[$query->getName()] = $query;
        }

        static::assertValidConfig($mutations);
        foreach ($mutations as $mutationName => $mutationConfig) {
            $mutation = Mutation::create($mutationName, $mutationConfig);
            $this->mutationFields[$mutation->getName()] = $mutation;
        }

        static::assertValidConfig($interfaces);
        foreach ($interfaces as $interfaceName => $interfaceConfig) {
            static::assertValidName($interfaceName);
            $interface = InterfaceType::create($interfaceName, $interfaceConfig);
            $this->addInterface($interface);
        }

        static::assertValidConfig($unions);
        foreach ($unions as $unionName => $unionConfig) {
            static::assertValidName($unionName);
            $union = UnionType::create($unionName, $unionConfig);
            $this->addUnion($union);
        }

        static::assertValidConfig($models);
        foreach ($models as $modelName => $modelConfig) {
             $model = ModelType::create($modelName, $modelConfig);
             $this->addModel($model);
        }

        static::assertValidConfig($enums);
        foreach ($enums as $enumName => $enumConfig) {
            Schema::assertValidConfig($enumConfig, ['values', 'description']);
            $values = $enumConfig['values'] ?? null;
            Schema::invariant($values, 'No values passed to enum %s', $enumName);
            $description = $enumConfig['description'] ?? null;
            $enum = Enum::create($enumName, $enumConfig['values'], $description);
            $this->addEnum($enum);
        }

        static::assertValidConfig($scalars);
        foreach ($scalars as $scalarName => $scalarConfig) {
            $scalar = Scalar::create($scalarName, $scalarConfig);
            $this->addScalar($scalar);
        }
        Benchmark::start('process-models');
        $this->processModels();
        Benchmark::end('process-models');

        Benchmark::start('schema-updates');
        $this->applySchemaUpdates($schemaConfig);
        echo Benchmark::end('schema-updates') . PHP_EOL;
        foreach ($this->models as $modelType) {
            $this->addType($modelType);
        }

        $queryType = Type::create(self::QUERY_TYPE, [
            'fields' => $this->queryFields,
        ]);
        $this->types[self::QUERY_TYPE] = $queryType;

        if (!empty($this->mutationFields)) {
            $mutationType = Type::create(self::MUTATION_TYPE, [
                'fields' => $this->mutationFields,
            ]);
            $this->types[self::MUTATION_TYPE] = $mutationType;
        }
        echo Benchmark::end('apply-config') . PHP_EOL;
        return $this;
    }

    /**
     * @throws SchemaBuilderException
     */
    private function processModels(): void
    {
        foreach ($this->getModels() as $modelType) {
            // Apply default plugins
            $id = $modelType->getModel()->getIdentifier();
            $defaultPlugins = $this->getDefault(['models', $id, 'plugins']) ?: [];
            $modelType->setDefaultPlugins($defaultPlugins);

            $modelType->buildOperations();

            foreach ($modelType->getOperations() as $operationName => $operationType) {
                Schema::invariant(
                    $operationType instanceof ModelOperation,
                    'Invalid operation defined on %s. Must implement %s',
                    $modelType->getName(),
                    ModelOperation::class
                );
                $defaultPlugins = $this->getDefault(['operations', $operationName, 'plugins']) ?: [];
                /* @var ModelQuery|ModelMutation $operationType */
                $operationType->setDefaultPlugins($defaultPlugins);

                if ($operationType instanceof ModelQuery) {
                    $this->queryFields[$operationType->getName()] = $operationType;
                } else {
                    if ($operationType instanceof ModelMutation) {
                        $this->mutationFields[$operationType->getName()] = $operationType;
                    }
                }
            }
        }

    }
    /**
     * @param array $schemaConfig
     * @throws SchemaBuilderException
     * @throws Exception
     */
    private function applySchemaUpdates(array $schemaConfig): void
    {
        $builders = $schemaConfig['builders'] ?? [];
        foreach ($builders as $builderClass) {
            static::invariant(
                is_subclass_of($builderClass, SchemaUpdater::class),
                'The schema builder %s is not an instance of %s',
                $builderClass,
                SchemaUpdater::class
            );
            $builderClass::updateSchema($this);
        }
        // Create a map of all the lists we need to apply plugins to, and their
        // required plugin interface(s)
        $allTypeFields = [];
        $allModelFields = [];
        foreach ($this->types as $type) {
            $pluggedFields = array_filter($type->getFields(), function (Field $field) {
                return !empty($field->getPlugins());
            });
            $allTypeFields = array_merge($allTypeFields, $pluggedFields);
        }
        foreach ($this->models as $model) {
            $pluggedFields = array_filter($model->getFields(), function (Field $field) {
                return !empty($field->getPlugins());
            });
            $allModelFields = array_merge($allModelFields, $pluggedFields);
        }

        // Create a list of everything in the schema that is pluggable, including fields added to types.
        // "src": the list of things to test
        // "req": the required interface(s)
        $allComponents = [
            [ 'src' => $this->types, 'req' => [TypePlugin::class] ],
            [ 'src' => $this->models, 'req' => [ModelTypePlugin::class] ],
            [ 'src' => $this->queryFields, 'req' => [
                FieldPlugin::class,
                ModelFieldPlugin::class,
                QueryPlugin::class,
                ModelQueryPlugin::class
            ]],
            [ 'src' => $this->mutationFields, 'req' => [
                FieldPlugin::class,
                MutationPlugin::class,
                ModelMutationPlugin::class
            ]],
            [ 'src' => $allTypeFields, 'req' => [FieldPlugin::class] ],
            [ 'src' => $allModelFields, 'req' => [
                FieldPlugin::class,
                ModelFieldPlugin::class,
            ]],
        ];
        $schemaUpdates = [];
        foreach($allComponents as $spec) {
            foreach ($spec['src'] as $component) {
                /* @var Type|Field $component */
                foreach ($component->loadPlugins() as $data) {
                    list ($plugin) = $data;
                    if ($plugin instanceof SchemaUpdater) {
                        $schemaUpdates[get_class($plugin)] = get_class($plugin);
                    }
                }
            }
        }
        /* @var SchemaUpdater $builder */
        foreach ($schemaUpdates as $class) {
            $class::updateSchema($this);
        }
        foreach ($allComponents as $spec) {
            foreach ($spec['src'] as $component) {
                /* @var Type|Field $component */
                foreach ($component->loadPlugins() as $data) {
                    /* @var QueryPlugin|MutationPlugin|TypePlugin|ModelTypePlugin $plugin */
                    list ($plugin, $config) = $data;
                    foreach ($spec['req'] as $pluginInterface) {
                        if ($plugin instanceof $pluginInterface) {
                            try {
                                $id = 'plugin-apply-'  . $plugin->getIdentifier() . '-' . $component->getName();
                                Benchmark::start($id);
                                $plugin->apply($component, $this, $config);
                                echo Benchmark::end($id) . PHP_EOL;
                                break;
                            } catch (SchemaBuilderException $e) {
                                throw new SchemaBuilderException(sprintf(
                                    'Failed to apply plugin %s to %s. Got error %s',
                                    get_class($plugin),
                                    $component->getName(),
                                    $e->getMessage()
                                ));
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @return Schema
     * @throws SchemaBuilderException
     */
    public function loadFromConfig(): Schema
    {
        $schemas = $this->config()->get('schemas');
        static::invariant($schemas, 'There are no schemas defined in the config');
        $schema = $schemas[$this->schemaKey] ?? null;
        static::invariant($schema, 'Schema "%s" is not configured', $this->schemaKey);

        // Gather all the global config first
        $mergedSchema = $schemas[self::ALL] ?? [];

        // Flushless global sources
        $globalSrcs = $mergedSchema['src'] ?? [];
        if (is_string($globalSrcs)) {
            $globalSrcs = [Schema::ALL => $globalSrcs];
        }

        Schema::assertValidConfig($globalSrcs);
        foreach ($globalSrcs as $configSrc => $data) {
            if ($data === false) {
                continue;
            }
            $sourcedConfig = $this->loadConfigFromSource($data);
            $mergedSchema = array_replace_recursive($mergedSchema, $sourcedConfig);
        }

        // Schema-specific flushless sources
        $configSrcs = $schema['src'] ?? [];
        if (is_string($configSrcs)) {
            $configSrcs = [$this->schemaKey => $configSrcs];
        }
        Schema::assertValidConfig($configSrcs);
        foreach ($configSrcs as $configSrc => $data) {
            if ($data === false) {
                continue;
            }
            $sourcedConfig = $this->loadConfigFromSource($data);
            $mergedSchema = array_replace_recursive($mergedSchema, $sourcedConfig);
        }

        // Finally, apply the standard _config schema
        $mergedSchema = array_replace_recursive($mergedSchema, $schema);

        $this->applyConfig($mergedSchema);

        return $this;
    }

    /**
     * @param string $dir
     * @return array
     * @throws SchemaBuilderException
     */
    public function loadConfigFromSource(string $dir): array
    {
        $resolvedDir = ModuleResourceLoader::singleton()->resolvePath($dir);
        $absConfigSrc = Path::join(BASE_PATH, $resolvedDir);
        static::invariant(
            is_dir($absConfigSrc),
            'Source config directory %s does not exist on schema %s',
            $absConfigSrc,
            $this->schemaKey
        );

        $config = [
            self::DEFAULTS => [],
            self::TYPES => [],
            self::MODELS => [],
            self::QUERIES => [],
            self::MUTATIONS => [],
            self::ENUMS => [],
            self::INTERFACES => [],
            self::UNIONS => [],
            self::SCALARS => [],
        ];

        $finder = new Finder();
        $yamlFiles = $finder->files()->in($absConfigSrc)->name('*.yml');

        /* @var SplFileInfo $yamlFile */
        foreach ($yamlFiles as $yamlFile) {
            try {
                $contents = $yamlFile->getContents();
                // fail gracefully on empty files
                if (empty($contents)) {
                    continue;
                }
                $yaml = Yaml::parseFile($yamlFile->getPathname());
            } catch (ParseException $e) {
                throw new SchemaBuilderException(sprintf(
                    'Could not parse YAML config for schema %s on file %s. Got error: %s',
                    $this->schemaKey,
                    $yamlFile->getPathname(),
                    $e->getMessage()
                ));
            }
            // Friendly check to see if the config was accidentally keyed to a schema
            Schema::invariant(
                !isset($yaml[$this->schemaKey]),
                'Sourced config file %s does not need a schema key. It is implicitly "%s".',
                $yamlFile->getPathname(),
                $this->schemaKey
            );
            // If the file is in the root src dir, e.g. _graphql/models.yml,
            // then allow the filename to be the namespace.
            if ($yamlFile->getPath() === $absConfigSrc) {
                $namespace = $yamlFile->getBasename('.yml');
            } else {
                // Otherwise, the directory name is the namespace, e.g _graphql/models/myfile.yml
                $namespace = basename($yamlFile->getPath());
            }

            // if the yaml file was in a namespace directory, e.g. "models/" or "types/", the key is implied.
            if (isset($config[$namespace])) {
                $config[$namespace] = array_merge_recursive($config[$namespace], $yaml);
            } else {
                $config = array_merge_recursive($config, $yaml);
            }
        }

        return $config;
    }

    /**
     * @throws SchemaBuilderException
     */
    public function save(): void
    {
        $this->validate();
        $this->getStore()->persistSchema($this);
    }

    public function load(): GraphQLSchema
    {
        return $this->getStore()->getSchema();
    }

    /**
     * @param string $key
     * @return Schema
     * @throws SchemaBuilderException
     */
    public static function get(string $key): self
    {
        return static::create($key)->loadFromConfig();
    }

    /**
     * @throws SchemaBuilderException
     */
    public function validate(): void
    {
        $allNames = array_merge(
            array_keys($this->types),
            array_keys($this->enums),
            array_keys($this->interfaces),
            array_keys($this->unions),
            array_keys($this->scalars)
        );
        $dupes = [];
        foreach(array_count_values($allNames) as $val => $count) {
            if ($count > 1) {
                $dupes[] = $val;
            }
        }

        static::invariant(
            empty($dupes),
            'Your schema has multiple types with the same name. See %s',
            implode(', ', $dupes)
        );

        $validators = array_merge(
            $this->types,
            $this->queryFields,
            $this->mutationFields,
            $this->enums,
            $this->interfaces,
            $this->unions,
            $this->scalars
        );
        /* @var SchemaValidator $validator */
        foreach ($validators as $validator) {
            $validator->validate();
        }
    }

    /**
     * @return string
     */
    public function getSchemaKey(): string
    {
        return $this->schemaKey;
    }

    /**
     * @param string $key
     * @return $this
     */
    public function setSchemaKey(string $key): self
    {
        $this->schemaKey = $key;

        return $this;
    }

    /**
     * Return a default by dot.separated.syntax
     * @param string|array $path
     * @return array|string|bool|null
     * @throws SchemaBuilderException
     */
    public function getDefault($path)
    {
        Schema::invariant(
            is_array($path) || is_string($path),
            'getDefaults() must be passed an array or string'
        );
        $parts = is_string($path) ? explode('.', $path) : $path;
        $scope = $this->defaults;
        foreach ($parts as $part) {
            $scope = $scope[$part] ?? null;
            if (!is_array($scope)) {
                break;
            }
        }

        return $scope;
    }
    /**
     * @param Type $type
     * @param callable|null $callback
     * @return Schema
     * @throws SchemaBuilderException
     */
    public function addType(Type $type, ?callable $callback = null): Schema
    {
        $existing = $this->types[$type->getName()] ?? null;
        $typeObj = $existing ? $existing->mergeWith($type) : $type;
        $this->types[$type->getName()] = $typeObj;
        if ($callback) {
            $callback($typeObj);
        }
        return $this;
    }

    /**
     * @param string $name
     * @return Type|null
     */
    public function getType(string $name): ?Type
    {
        return $this->types[$name] ?? null;
    }

    /**
     * @param string $name
     * @return Type
     * @throws SchemaBuilderException
     */
    public function findOrMakeType(string $name): Type
    {
        $existing = $this->getType($name);
        if ($existing) {
            return $existing;
        }
        $this->addType(Type::create($name));

        return $this->getType($name);
    }

    /**
     * @return Type[]
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    /**
     * @param Enum $enum
     * @return $this
     */
    public function addEnum(Enum $enum): self
    {
        $this->enums[$enum->getName()] = $enum;

        return $this;
    }

    /**
     * @return Enum[]
     */
    public function getEnums(): array
    {
        return $this->enums;
    }

    /**
     * @param $name
     * @return Enum|null
     */
    public function getEnum(string $name): ?Enum
    {
        return $this->enums[$name] ?? null;
    }

    /**
     * @return array
     */
    public function getScalars(): array
    {
        return $this->scalars;
    }

    /**
     * @param string $name
     * @return Scalar|null
     */
    public function getScalar(string $name): ?Scalar
    {
        return $this->scalars[$name] ?? null;
    }

    /**
     * @param Scalar $scalar
     * @return $this
     */
    public function addScalar(Scalar $scalar): self
    {
        $this->scalars[$scalar->getName()] = $scalar;

        return $this;
    }

    /**
     * @param ModelType $modelType
     * @param callable|null $callback
     * @return Schema
     * @throws SchemaBuilderException
     */
    public function addModel(ModelType $modelType, ?callable $callback = null): Schema
    {
        $existing = $this->models[$modelType->getName()] ?? null;
        $typeObj = $existing
            ? $existing->mergeWith($modelType)
            : $modelType;
        $this->models[$modelType->getName()] = $typeObj;
        foreach ($modelType->getExtraTypes() as $type) {
            if ($type instanceof ModelType) {
                $this->addModel($type);
            } else {
                $this->addType($type);
            }
        }
        if ($callback) {
            $callback($typeObj);
        }

        return $this;
    }

    /**
     * @param string $name
     * @return ModelType|null
     */
    public function getModel(string $name): ?ModelType
    {
        return $this->models[$name] ?? null;
    }

    /**
     * @return ModelType[]
     */
    public function getModels(): array
    {
        return $this->models;
    }

    /**
     * @param string $class
     * @return ModelType
     * @throws SchemaBuilderException
     */
    public function findOrMakeModel(string $class): ModelType
    {
        $newModel = ModelType::create($class);
        $name = $newModel->getName();
        $existing = $this->getModel($name);
        if ($existing) {
            return $existing;
        }
        $this->addModel($newModel);

        return $this->getModel($name);
    }

    /**
     * @param InterfaceType $type
     * @param callable|null $callback
     * @return $this
     * @throws SchemaBuilderException
     */
    public function addInterface(InterfaceType $type, ?callable $callback = null): self
    {
        $existing = $this->interfaces[$type->getName()] ?? null;
        $typeObj = $existing ? $existing->mergeWith($type) : $type;
        $this->interfaces[$type->getName()] = $typeObj;
        if ($callback) {
            $callback($typeObj);
        }
        return $this;

    }

    /**
     * @param string $name
     * @return InterfaceType|null
     */
    public function getInterface(string $name): ?InterfaceType
    {
        return $this->interfaces[$name] ?? null;
    }

    /**
     * @return InterfaceType[]
     */
    public function getInterfaces(): array
    {
        return $this->interfaces;
    }

    /**
     * @param UnionType $union
     * @param callable|null $callback
     * @return $this
     */
    public function addUnion(UnionType $union, ?callable $callback = null): self
    {
        $existing = $this->unions[$union->getName()] ?? null;
        $typeObj = $existing ? $existing->mergeWith($union) : $union;
        $this->unions[$union->getName()] = $typeObj;
        if ($callback) {
            $callback($typeObj);
        }
        return $this;
    }

    /**
     * @param string $name
     * @return UnionType|null
     */
    public function getUnion(string $name): ?UnionType
    {
        return $this->unions[$name] ?? null;
    }

    /**
     * @return array
     */
    public function getUnions(): array
    {
        return $this->unions;
    }

    /**
     * @return array
     */
    public static function getInternalTypes(): array
    {
        return ['String', 'Boolean', 'Int', 'Float', 'ID'];
    }

    /**
     * Pluralise a name
     *
     * @param string $typeName
     * @return string
     * @throws SchemaBuilderException
     */
    public static function pluralise($typeName): string
    {
        $callable = static::config()->get('pluraliser');
        Schema::invariant(
            is_callable($callable),
            'Schema does not have a valid callable "pluraliser" property set in its config'
        );

        return call_user_func_array($callable, [$typeName]);
    }

    /**
     * @param string $typeName
     * @return string
     */
    public static function pluraliser(string $typeName): string
    {
        // Ported from DataObject::plural_name()
        if (preg_match('/[^aeiou]y$/i', $typeName)) {
            $typeName = substr($typeName, 0, -1) . 'ie';
        }
        $typeName .= 's';
        return $typeName;
    }

    /**
     * @param array $config
     * @param array $allowedKeys
     * @param array $requiredKeys
     * @throws SchemaBuilderException
     */
    public static function assertValidConfig(array $config, $allowedKeys = [], $requiredKeys = []): void
    {
        static::invariant(
            empty($config) || ArrayLib::is_associative($config),
            '%s configurations must be key value pairs of names to configurations.
            Did you include an indexed array in your config?',
            static::class
        );

        if (!empty($allowedKeys)) {
            $invalidKeys = array_diff(array_keys($config), $allowedKeys);
            static::invariant(
                empty($invalidKeys),
                'Config contains invalid keys: %s',
                implode(',', $invalidKeys)
            );
        }

        if (!empty($requiredKeys)) {
            $missingKeys = array_diff($requiredKeys, array_keys($config));
            static::invariant(
                empty($missingKeys),
                'Config is missing required keys: %s',
                implode(',', $missingKeys)
            );
        }
    }

    /**
     * @param $name
     * @throws SchemaBuilderException
     */
    public static function assertValidName($name): void
    {
        static::invariant(
          preg_match(' /[_A-Za-z][_0-9A-Za-z]*/', $name),
          'Invalid name: %s. Names must only use underscores and alphanumeric characters, and cannot
          begin with a number.',
          $name
        );
    }

    /**
     * @param $test
     * @param string $message
     * @param mixed ...$params
     * @throws SchemaBuilderException
     */
    public static function invariant($test, $message = '', ...$params): void
    {
        if (!$test) {
            $message = sprintf($message, ...$params);
            throw new SchemaBuilderException($message);
        }
    }


    /**
     * @return SchemaStorageInterface
     */
    public function getStore(): SchemaStorageInterface
    {
        return $this->schemaStore;
    }

    /**
     * @param SchemaStorageInterface $store
     * @return $this
     */
    public function setStore(SchemaStorageInterface $store): self
    {
        $this->schemaStore = $store;

        return $this;
    }

    /**
     * Used for logging in tasks
     * @param string $message
     */
    public static function message(string $message): void
    {
        if (Director::is_cli()) {
            fwrite(STDOUT, $message . PHP_EOL);
        } else {
            echo $message . "<br>";
        }
    }
}
