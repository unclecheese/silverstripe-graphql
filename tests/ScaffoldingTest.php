<?php

namespace SilverStripe\GraphQL;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\GraphQL\Scaffolding\Scaffolders\DataObjectScaffolder;
use SilverStripe\GraphQL\Tests\Fake\DataObjectFake;
use SilverStripe\GraphQL\Tests\Fake\RestrictedDataObjectFake;
use SilverStripe\GraphQL\Tests\Fake\OperationScaffolderFake;
use SilverStripe\GraphQL\Tests\Fake\FakeResolver;
use SilverStripe\GraphQL\Tests\Fake\FakeSiteTree;
use SilverStripe\GraphQL\Tests\Fake\FakePage;
use SilverStripe\GraphQL\Tests\Fake\FakeRedirectorPage;
use Doctrine\Instantiator\Exception\InvalidArgumentException;
use SilverStripe\GraphQL\Scaffolding\Scaffolders\SchemaScaffolder;
use SilverStripe\GraphQL\Scaffolding\Scaffolders\OperationScaffolder;
use SilverStripe\GraphQL\Scaffolding\Scaffolders\ArgumentScaffolder;
use SilverStripe\GraphQL\Scaffolding\Scaffolders\QueryScaffolder;
use SilverStripe\GraphQL\Scaffolding\Scaffolders\MutationScaffolder;
use SilverStripe\GraphQL\Scaffolding\Scaffolders\CRUD\Create;
use SilverStripe\GraphQL\Scaffolding\Scaffolders\CRUD\Read;
use SilverStripe\GraphQL\Scaffolding\Scaffolders\CRUD\Update;
use SilverStripe\GraphQL\Scaffolding\Scaffolders\CRUD\Delete;
use SilverStripe\GraphQL\Scaffolding\Interfaces\CRUDInterface;
use SilverStripe\GraphQL\Scaffolding\Util\ArgsParser;
use SilverStripe\GraphQL\Scaffolding\Util\TypeParser;
use SilverStripe\GraphQL\Scaffolding\Util\OperationList;
use SilverStripe\GraphQL\Manager;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\StringType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\IntType;
use GraphQL\Type\Definition\IDType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ResolveInfo;
use SilverStripe\Security\Member;
use SilverStripe\Assets\File;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use Exception;

class ScaffoldingTest extends SapphireTest
{

    protected $extraDataObjects = [
        'SilverStripe\GraphQL\Tests\Fake\DataObjectFake',
        'SilverStripe\GraphQL\Tests\Fake\RestrictedDataObjectFake'
    ];

    public function testDataObjectScaffolderConstructor()
    {
        $scaffolder = $this->getFakeScaffolder();
        $this->assertEquals(DataObjectFake::class, $scaffolder->getDataObjectClass());
        $this->assertInstanceOf(DataObjectFake::class, $scaffolder->getDataObjectInstance());

        $this->setExpectedExceptionRegExp(
            InvalidArgumentException::class,
            '/non-existent classname/'
        );
        $scaffolder = new DataObjectScaffolder('fail');
    }

    public function testDataObjectScaffolderFields()
    {
        $scaffolder = $this->getFakeScaffolder();
        
        $scaffolder->addField('MyField');
        $this->assertEquals(['MyField'], $scaffolder->getFields()->column('Name'));
        
        $scaffolder->addField('MyField', 'Some description');
        $this->assertEquals(
            'Some description',
            $scaffolder->getFieldDescription('MyField')
        );

        $scaffolder->addFields([
            'MyField',
            'MyInt' => 'Int description'
        ]);
        
        $this->assertEquals(['MyField', 'MyInt'], $scaffolder->getFields()->column('Name'));
        $this->assertNull(
            $scaffolder->getFieldDescription('MyField')
        );
        $this->assertEquals(
            'Int description',
            $scaffolder->getFieldDescription('MyInt')
        );

        $scaffolder->setFieldDescription('MyInt', 'New int description');
        $this->assertEquals(
            'New int description',
            $scaffolder->getFieldDescription('MyInt')
        );

        $scaffolder = $this->getFakeScaffolder();
        $scaffolder->addAllFields();
        $this->assertEquals(
            ['ID','ClassName','LastEdited','Created','MyField','MyInt'],
            $scaffolder->getFields()->column('Name')
        );

        $scaffolder = $this->getFakeScaffolder();
        $scaffolder->addAllFields(true);
        $this->assertEquals(
            ['ID','ClassName','LastEdited','Created','MyField','MyInt', 'Author'],
            $scaffolder->getFields()->column('Name')
        );

        $scaffolder = $this->getFakeScaffolder();
        $scaffolder->addAllFieldsExcept('MyInt');
        $this->assertEquals(
            ['ID','ClassName','LastEdited','Created','MyField'],
            $scaffolder->getFields()->column('Name')
        );

        $scaffolder = $this->getFakeScaffolder();
        $scaffolder->addAllFieldsExcept('MyInt', true);
        $this->assertEquals(
            ['ID','ClassName','LastEdited','Created','MyField','Author'],
            $scaffolder->getFields()->column('Name')
        );

        $scaffolder->removeField('ClassName');
        $this->assertEquals(
            ['ID','LastEdited','Created','MyField','Author'],
            $scaffolder->getFields()->column('Name')
        );
        $scaffolder->removeFields(['LastEdited','Created']);
        $this->assertEquals(
            ['ID','MyField','Author'],
            $scaffolder->getFields()->column('Name')
        );
    }


    public function testDataObjectScaffolderOperations()
    {
        $scaffolder = $this->getFakeScaffolder();
        $op = $scaffolder->operation(SchemaScaffolder::CREATE);

        $this->assertInstanceOf(CRUDInterface::class, $op);

        // Ensure we get back the same reference
        $op->Test = true;
        $op = $scaffolder->operation(SchemaScaffolder::CREATE);
        $this->assertEquals(true, $op->Test);

        // Ensure duplicates aren't created
        $scaffolder->operation(SchemaScaffolder::DELETE);
        $this->assertEquals(2, $scaffolder->getOperations()->count());

        $scaffolder->removeOperation(SchemaScaffolder::DELETE);
        $this->assertEquals(1, $scaffolder->getOperations()->count());

        $this->setExpectedExceptionRegExp(
            InvalidArgumentException::class,
            '/Invalid operation/'
        );
        $scaffolder = $this->getFakeScaffolder();
        $scaffolder->operation('fail');
    }

    public function testDataObjectScaffolderNestedQueries()
    {
        $scaffolder = $this->getFakeScaffolder();
        $query = $scaffolder->nestedQuery('Files');

        $this->assertInstanceOf(QueryScaffolder::class, $query);

        // Ensure we get back the same reference
        $query->Test = true;
        $query = $scaffolder->nestedQuery('Files');
        $this->assertEquals(true, $query->Test);

        // Ensure duplicates aren't created
        $this->assertEquals(1, $scaffolder->getNestedQueries()->count());

        $this->setExpectedExceptionRegExp(
            InvalidArgumentException::class,
            '/returns a DataList or ArrayList/'
        );
        $scaffolder = $this->getFakeScaffolder();
        $scaffolder->nestedQuery('MyField');

    }

    public function testDataObjectScaffolderDependentClasses()
    {
        $scaffolder = $this->getFakeScaffolder();
        $this->assertEquals([], $scaffolder->getDependentClasses());
        $scaffolder->nestedQuery('Files');
        $this->assertEquals(
            [$scaffolder->getDataObjectInstance()->Files()->dataClass()],
            $scaffolder->getDependentClasses()
        );

        $scaffolder->addField('Author');
        $this->assertEquals(
            [
                $scaffolder->getDataObjectInstance()->Author()->class,
                $scaffolder->getDataObjectInstance()->Files()->dataClass()
            ],
            $scaffolder->getDependentClasses()
        );
    }

    public function testDataObjectScaffolderAncestralClasses()
    {
        $scaffolder = new DataObjectScaffolder(FakeRedirectorPage::class);
        $classes = $scaffolder->getAncestralClasses();

        $this->assertEquals([
            FakePage::class,
            FakeSiteTree::class
        ], $classes);

    }

    public function testDataObjectScaffolderApplyConfig()
    {
        $observer = $this->getMockBuilder(DataObjectScaffolder::class)
            ->setConstructorArgs([DataObjectFake::class])
            ->setMethods(['addFields','removeFields','operation','nestedQuery', 'setFieldDescription'])
            ->getMock();

        $observer->expects($this->once())
            ->method('addFields')
            ->with($this->equalTo(['ID','MyField','MyInt']));

        $observer->expects($this->once())
            ->method('removeFields')
            ->with($this->equalTo(['ID']));

        $observer->expects($this->exactly(2))
            ->method('operation')
            ->withConsecutive(
                [$this->equalTo(SchemaScaffolder::CREATE)],
                [$this->equalTo(SchemaScaffolder::READ)]
            )
            ->will($this->returnValue(
                $this->getMockBuilder(Create::class)
                    ->disableOriginalConstructor()
                    ->getMock()
            ));

        $observer->expects($this->once())
            ->method('nestedQuery')
            ->with($this->equalTo('Files'))
            ->will($this->returnValue(
                $this->getMockBuilder(QueryScaffolder::class)
                    ->disableOriginalConstructor()
                    ->getMock()
            ));

        $observer->expects($this->once())
            ->method('setFieldDescription')
            ->with(
                $this->equalTo('MyField'),
                $this->equalTo('This is myfield')
            );

        $observer->applyConfig([
            'fields' => ['ID', 'MyField','MyInt'],
            'excludeFields' => ['ID'],
            'operations' => ['create' => true,'read' => true],
            'nestedQueries' => ['Files' => true],
            'fieldDescriptions' => [
                'MyField' => 'This is myfield'
            ]
        ]);

    }

    public function testDataObjectScaffolderApplyConfigNoFieldsException()
    {
        $scaffolder = $this->getFakeScaffolder();

        // Must have "fields" defined
        $this->setExpectedExceptionRegExp(
            Exception::class,
            '/No array of fields/'
        );
        $scaffolder->applyConfig([
            'operations' => ['create' => true]
        ]);
    }

    public function testDataObjectScaffolderApplyConfigInvalidFieldsException()
    {
        $scaffolder = $this->getFakeScaffolder();

        // Invalid fields
        $this->setExpectedExceptionRegExp(
            Exception::class,
            '/Fields must be an array/'
        );
        $scaffolder->applyConfig([
            'fields' => 'fail'
        ]);
    }

    public function testDataObjectScaffolderApplyConfigInvalidFieldsExceptException()
    {
        $scaffolder = $this->getFakeScaffolder();

        // Invalid fieldsExcept
        $this->setExpectedExceptionRegExp(
            InvalidArgumentException::class,
            '/"excludeFields" must be an enumerated list/'
        );
        $scaffolder->applyConfig([
            'fields' => ['MyField'],
            'excludeFields' => 'fail'
        ]);
    }

    public function testDataObjectScaffolderApplyConfigInvalidOperationsException()
    {
        $scaffolder = $this->getFakeScaffolder();

        // Invalid operations
        $this->setExpectedExceptionRegExp(
            Exception::class,
            '/Operations field must be a map/'
        );
        $scaffolder->applyConfig([
            'fields' => ['MyField'],
            'operations' => ['create']
        ]);
    }

    public function testDataObjectScaffolderApplyConfigInvalidNestedQueriesException()
    {
        $scaffolder = $this->getFakeScaffolder();

        // Invalid nested queries
        $this->setExpectedExceptionRegExp(
            Exception::class,
            '/"nestedQueries" must be a map of relation name/'
        );
        $scaffolder->applyConfig([
            'fields' => ['MyField'],
            'nestedQueries' => ['Files']
        ]);
    }



    public function testDataObjectScaffolderWildcards()
    {
        $scaffolder = $this->getFakeScaffolder();
        $scaffolder->applyConfig([
            'fields' => SchemaScaffolder::ALL,
            'operations' => SchemaScaffolder::ALL
        ]);
        $ops = $scaffolder->getOperations();

        $this->assertInstanceOf(Create::class, $ops->findByIdentifier(SchemaScaffolder::CREATE));
        $this->assertInstanceOf(Delete::class, $ops->findByIdentifier(SchemaScaffolder::DELETE));
        $this->assertInstanceOf(Read::class, $ops->findByIdentifier(SchemaScaffolder::READ));
        $this->assertInstanceOf(Update::class, $ops->findByIdentifier(SchemaScaffolder::UPDATE));

        $this->assertEquals(
            ['ID','ClassName','LastEdited','Created','MyField','MyInt','Author'],
            $scaffolder->getFields()->column('Name')
        );
    }

    public function testDataObjectScaffolderScaffold()
    {
        $scaffolder = $this->getFakeScaffolder();
        $scaffolder->addFields(['MyField', 'Author'])
                   ->nestedQuery('Files');

        $objectType = $scaffolder->scaffold(new Manager());

        $this->assertInstanceof(ObjectType::class, $objectType);
        $config = $objectType->config;

        $this->assertEquals($scaffolder->typeName(), $config['name']);
        $this->assertEquals(['MyField', 'Author', 'Files'], array_keys($config['fields']));
    }

    public function testDataObjectScaffolderScaffoldFieldException()
    {
        $this->setExpectedExceptionRegExp(
            InvalidArgumentException::class,
            '/Invalid field/'
        );
        $scaffolder = $this->getFakeScaffolder()
            ->addFields(['not a field'])
            ->scaffold(new Manager());
    }

    public function testDataObjectScaffolderScaffoldNestedQueryException()
    {
        $this->setExpectedExceptionRegExp(
            InvalidArgumentException::class,
            '/returns a list/'
        );
        $scaffolder = $this->getFakeScaffolder()
            ->addFields(['Files'])
            ->scaffold(new Manager());
    }

    public function testDataObjectScaffolderAddToManager()
    {
        $manager = new Manager();
        $scaffolder = $this->getFakeScaffolder()
            ->addFields(['MyField'])
            ->operation(SchemaScaffolder::CREATE)
                ->end()
            ->operation(SchemaScaffolder::READ)
                ->end();

        $scaffolder->addToManager($manager);

        $schema = $manager->schema();
        $queryConfig = $schema->getQueryType()->config;
        $mutationConfig = $schema->getMutationType()->config;
        $types = $schema->getTypeMap();

        $this->assertArrayHasKey(
            (new Read(DataObjectFake::class))->getName(),
            $queryConfig['fields']
        );

        $this->assertArrayHasKey(
            (new Create(DataObjectFake::class))->getName(),
            $mutationConfig['fields']
        );

        $this->assertTrue($manager->hasType($scaffolder->typeName()));

    }


    public function testSchemaScaffolderTypes()
    {

        $scaffolder = new SchemaScaffolder();
        $type = $scaffolder->type(DataObjectFake::class);
        $type->Test = true;
        $type2 = $scaffolder->type(DataObjectFake::class);

        $query = $scaffolder->query('testQuery', DataObjectFake::class);
        $query->Test = true;
        $query2 = $scaffolder->query('testQuery', DataObjectFake::class);

        $mutation = $scaffolder->mutation('testMutation', DataObjectFake::class);
        $mutation->Test = true;
        $mutation2 = $scaffolder->mutation('testMutation', DataObjectFake::class);

        $this->assertEquals(1, count($scaffolder->getTypes()));
        $this->assertTrue($type2->Test);

        $this->assertEquals(1, $scaffolder->getQueries()->count());
        $this->assertTrue($query2->Test);

        $this->assertEquals(1, $scaffolder->getMutations()->count());
        $this->assertTrue($mutation2->Test);

        $scaffolder->removeQuery('testQuery');
        $this->assertEquals(0, $scaffolder->getQueries()->count());

        $scaffolder->removeMutation('testMutation');
        $this->assertEquals(0, $scaffolder->getMutations()->count());

    }

    public function testSchemaScaffolderAddToManager()
    {
        Config::inst()->update(FakePage::class, 'db', [
            'TestPageField' => 'Varchar'
        ]);

        $manager = new Manager();
        $scaffolder = (new SchemaScaffolder())
            ->type(FakeRedirectorPage::class)
                ->addFields(['Created','TestPageField', 'RedirectionType'])
                ->operation(SchemaScaffolder::CREATE)
                    ->end()
                ->operation(SchemaScaffolder::READ)
                    ->end()
                ->end()
            ->type(DataObjectFake::class)
                ->addFields(['Author'])
                ->nestedQuery('Files')
                    ->end()
                ->end()
            ->query('testQuery', DataObjectFake::class)
                ->end()
            ->mutation('testMutation', DataObjectFake::class)
                ->end();

        $scaffolder->addToManager($manager);
        $queries = $scaffolder->getQueries();
        $mutations = $scaffolder->getMutations();
        $types = $scaffolder->getTypes();

        $classNames = array_map(function ($scaffold) {
            return $scaffold->getDataObjectClass();
        }, $types);

        $this->assertEquals([
            FakeRedirectorPage::class,
            DataObjectFake::class,
            FakePage::class,
            FakeSiteTree::class,
            Member::class,
            File::class
        ], $classNames);

        $this->assertEquals(
            ['Created', 'TestPageField', 'RedirectionType'],
            $scaffolder->type(FakeRedirectorPage::class)->getFields()->column('Name')
        );

        $this->assertEquals(
            ['Created', 'TestPageField'],
            $scaffolder->type(FakePage::class)->getFields()->column('Name')
        );

        $this->assertEquals(
            ['Created'],
            $scaffolder->type(FakeSiteTree::class)->getFields()->column('Name')
        );


        $this->assertEquals('testQuery', $scaffolder->getQueries()->first()->getName());
        $this->assertEquals('testMutation', $scaffolder->getMutations()->first()->getName());

        $this->assertInstanceof(
            Read::class,
            $scaffolder->type(FakeRedirectorPage::class)->getOperations()->findByIdentifier(SchemaScaffolder::READ)
        );
        $this->assertInstanceof(
            Read::class,
            $scaffolder->type(FakePage::class)->getOperations()->findByIdentifier(SchemaScaffolder::READ)
        );
        $this->assertInstanceof(
            Read::class,
            $scaffolder->type(FakeSiteTree::class)->getOperations()->findByIdentifier(SchemaScaffolder::READ)
        );

        $this->assertInstanceof(
            Create::class,
            $scaffolder->type(FakeRedirectorPage::class)->getOperations()->findByIdentifier(SchemaScaffolder::CREATE)
        );
        $this->assertInstanceof(
            Create::class,
            $scaffolder->type(FakePage::class)->getOperations()->findByIdentifier(SchemaScaffolder::CREATE)
        );
        $this->assertInstanceof(
            Create::class,
            $scaffolder->type(FakeSiteTree::class)->getOperations()->findByIdentifier(SchemaScaffolder::CREATE)
        );
    }

    public function testSchemaScaffolderCreateFromConfigThrowsIfBadTypes()
    {
        $this->setExpectedExceptionRegExp(
            InvalidArgumentException::class,
            '/"types" must be a map of class name to settings/'
        );
        SchemaScaffolder::createFromConfig([
            'types' => ['fail']
        ]);
    }

    public function testSchemaScaffolderCreateFromConfigThrowsIfBadQueries()
    {
        $this->setExpectedExceptionRegExp(
            InvalidArgumentException::class,
            '/must be a map of operation name to settings/'
        );
        SchemaScaffolder::createFromConfig([
            'types' => [
                DataObjectFake::class => [
                    'fields' => '*'
                ]
            ],
            'queries' => ['fail']
        ]);
    }

    public function testSchemaScaffolderCreateFromConfig()
    {
        $observer = $this->getMockBuilder(SchemaScaffolder::class)
            ->setMethods(['query','mutation','type'])
            ->getMock();

        $observer->expects($this->once())
            ->method('query')
            ->will($this->returnValue(new QueryScaffolder('test', 'test')));

        $observer->expects($this->once())
            ->method('mutation')
            ->will($this->returnValue(new MutationScaffolder('test', 'test')));

        $observer->expects($this->once())
            ->method('type')
            ->willReturn(
                new DataObjectScaffolder(DataObjectFake::class)
            );

        Injector::inst()->registerService($observer, SchemaScaffolder::class);

        SchemaScaffolder::createFromConfig([
            'types' => [
                DataObjectFake::class => [
                    'fields' => ['MyField']
                ]
            ],
            'queries' => [
                'testQuery' => [
                    'type' => DataObjectFake::class,
                    'resolver' => FakeResolver::class
                ]
            ],
            'mutations' => [
                'testMutation' => [
                    'type' => DataObjectFake::class,
                    'resolver' => FakeResolver::class
                ]
            ]
        ]);
    }

    public function testOperationIdentifiers()
    {
        $this->assertEquals(
            Read::class,
            OperationScaffolder::getOperationScaffoldFromIdentifier(SchemaScaffolder::READ)
        );
        $this->assertEquals(
            Update::class,
            OperationScaffolder::getOperationScaffoldFromIdentifier(SchemaScaffolder::UPDATE)
        );
        $this->assertEquals(
            Delete::class,
            OperationScaffolder::getOperationScaffoldFromIdentifier(SchemaScaffolder::DELETE)
        );
        $this->assertEquals(
            Create::class,
            OperationScaffolder::getOperationScaffoldFromIdentifier(SchemaScaffolder::CREATE)
        );

    }

    public function testOperationScaffolderArgs()
    {
        $scaffolder = new OperationScaffolderFake('testOperation', 'testType');
        
        $this->assertEquals('testOperation', $scaffolder->getName());
        $scaffolder->addArgs([
            'One' => 'String',
            'Two' => 'Boolean'
        ]);
        $scaffolder->addArg(
            'One',
            'String',
            'One description',
            'One default'
        );

        $this->assertEquals([], array_diff(
            $scaffolder->getArgs()->column('argName'),
            ['One','Two']
        ));

        $argument = $scaffolder->getArgs()->find('argName', 'One');
        $this->assertInstanceOf(ArgumentScaffolder::class, $argument);
        $arr = $argument->toArray();
        $this->assertEquals('One description', $arr['description']);
        $this->assertEquals('One default', $arr['defaultValue']);

        $scaffolder->setArgDescriptions([
            'One' => 'Foo',
            'Two' => 'Bar'
        ]);

        $scaffolder->setArgDefaults([
            'One' => 'Feijoa',
            'Two' => 'Kiwifruit'
        ]);

        $argument = $scaffolder->getArgs()->find('argName', 'One');
        $arr = $argument->toArray();
        $this->assertEquals('Foo', $arr['description']);
        $this->assertEquals('Feijoa', $arr['defaultValue']);

        $argument = $scaffolder->getArgs()->find('argName', 'Two');
        $arr = $argument->toArray();
        $this->assertEquals('Bar', $arr['description']);
        $this->assertEquals('Kiwifruit', $arr['defaultValue']);

        $scaffolder->setArgDescription('One', 'Tui')
            ->setArgDefault('One', 'Moa');

        $argument = $scaffolder->getArgs()->find('argName', 'One');
        $arr = $argument->toArray();
        $this->assertEquals('Tui', $arr['description']);
        $this->assertEquals('Moa', $arr['defaultValue']);

        $scaffolder->removeArg('One');
        $this->assertEquals(['Two'], $scaffolder->getArgs()->column('argName'));
        $scaffolder->addArg('Test', 'String');
        $scaffolder->removeArgs(['Two','Test']);
        $this->assertFalse($scaffolder->getArgs()->exists());

        $ex = null;
        try {
            $scaffolder->setArgDescription('Nothing', 'Test');
        } catch (Exception $e) {
            $ex = $e;
        }

        $this->assertInstanceOf(InvalidArgumentException::class, $ex);
        $this->assertRegExp('/Tried to set description/', $ex->getMessage());

        $ex = null;
        try {
            $scaffolder->setArgDefault('Nothing', 'Test');
        } catch (Exception $e) {
            $ex = $e;
        }

        $this->assertInstanceOf(InvalidArgumentException::class, $ex);
        $this->assertRegExp('/Tried to set default/', $ex->getMessage());
    }

    public function testOperationScaffolderResolver()
    {
        $scaffolder = new OperationScaffolderFake('testOperation', 'testType');

        try {
            $scaffolder->setResolver(function () {
            });
            $scaffolder->setResolver(FakeResolver::class);
            $scaffolder->setResolver(new FakeResolver());
            $success = true;
        } catch (Exception $e) {
            $success = false;
        }

        $this->assertTrue($success);

        $this->setExpectedExceptionRegExp(
            InvalidArgumentException::class,
            '/closures, instances of/'
        );
        $scaffolder->setResolver('fail');
    }

    public function testOperationScaffolderAppliesConfig()
    {
        $scaffolder = new OperationScaffolderFake('testOperation', 'testType');
        
        
        $scaffolder->applyConfig([
            'args' => [
                'One' => 'String',
                'Two' => [
                    'type' => 'String',
                    'default' => 'Foo',
                    'description' => 'Bar'
                ]
            ],
            'resolver' => FakeResolver::class
        ]);

        $this->assertEquals([], array_diff(
            $scaffolder->getArgs()->column('argName'),
            ['One','Two']
        ));

        $arg = $scaffolder->getArgs()->find('argName', 'Two');
        $this->assertInstanceof(ArgumentScaffolder::class, $arg);
        $arr = $arg->toArray();
        $this->assertInstanceOf(StringType::class, $arr['type']);

        $ex = null;
        try {
            $scaffolder->applyConfig([
                'args' => 'fail'
            ]);
        } catch (Exception $e) {
            $ex = $e;
        }

        $this->assertInstanceof(Exception::class, $e);
        $this->assertRegExp('/args must be an array/', $e->getMessage());

        $ex = null;
        try {
            $scaffolder->applyConfig([
                'args' => [
                    'One' => [
                        'default' => 'Foo',
                        'description' => 'Bar'
                    ]
                ]
            ]);
        } catch (Exception $e) {
            $ex = $e;
        }

        $this->assertInstanceof(Exception::class, $e);
        $this->assertRegExp('/must have a type/', $e->getMessage());

        $ex = null;
        try {
            $scaffolder->applyConfig([
                'args' => [
                    'One' => false
                ]
            ]);
        } catch (Exception $e) {
            $ex = $e;
        }

        $this->assertInstanceof(Exception::class, $e);
        $this->assertRegExp('/should be mapped to a string or an array/', $e->getMessage());

    }

    public function testArgumentScaffolder()
    {
        $scaffolder = new ArgumentScaffolder('Test', 'String');
        $scaffolder->setRequired(false)
            ->setDescription('Description')
            ->setDefaultValue('Default');

        $this->assertEquals('Description', $scaffolder->getDescription());
        $this->assertEquals('Default', $scaffolder->getDefaultValue());
        $this->assertFalse($scaffolder->isRequired());

        $scaffolder->applyConfig([
            'description' => 'Foo',
            'default' => 'Bar',
            'required' => true
        ]);

        $this->assertEquals('Foo', $scaffolder->getDescription());
        $this->assertEquals('Bar', $scaffolder->getDefaultValue());
        $this->assertTrue($scaffolder->isRequired());

        $arr = $scaffolder->toArray();

        $this->assertEquals('Foo', $arr['description']);
        $this->assertEquals('Bar', $arr['defaultValue']);
        $this->assertInstanceOf(NonNull::class, $arr['type']);
        $this->assertInstanceOf(StringType::class, $arr['type']->getWrappedType());
    }

    public function testMutationScaffolder()
    {
        $observer = $this->getMockBuilder(Manager::class)
            ->setMethods(['addMutation'])
            ->getMock();
        $scaffolder = new MutationScaffolder('testMutation', 'test');
        $scaffolder->addArgs(['Test' => 'String']);
        $scaffold = $scaffolder->scaffold($manager = new Manager());
        $manager->addType($o = new ObjectType([
            'name' => 'test',
            'fields' => []
        ]));
        $o->Test = true;

        $this->assertEquals('testMutation', $scaffold['name']);
        $this->assertArrayHasKey('Test', $scaffold['args']);
        $this->assertTrue(is_callable($scaffold['resolve']));
        $this->assertTrue($scaffold['type']()->Test);

        $observer->expects($this->once())
            ->method('addMutation')
            ->with(
                $this->equalTo($scaffold),
                $this->equalTo('testMutation')
            );

        $scaffolder->addToManager($observer);
    }

    public function testQueryScaffolderUnpaginated()
    {
        $observer = $this->getMockBuilder(Manager::class)
            ->setMethods(['addQuery'])
            ->getMock();
        $scaffolder = new QueryScaffolder('testQuery', 'test');
        $scaffolder->setUsePagination(false);
        $scaffolder->addArgs(['Test' => 'String']);
        $scaffold = $scaffolder->scaffold($manager = new Manager());
        
        $manager->addType($o = new ObjectType([
            'name' => 'test',
            'fields' => []
        ]));
        $o->Test = true;

        $this->assertEquals('testQuery', $scaffold['name']);
        $this->assertArrayHasKey('Test', $scaffold['args']);
        $this->assertTrue(is_callable($scaffold['resolve']));
        $this->assertTrue($scaffold['type']()->Test);

        $observer->expects($this->once())
            ->method('addQuery')
            ->with(
                $this->equalTo($scaffold),
                $this->equalTo('testQuery')
            );

        $scaffolder->addToManager($observer);
    }

    public function testQueryScaffolderPaginated()
    {
        $scaffolder = new QueryScaffolder('testQuery', 'test');
        $scaffolder->setUsePagination(true);
        $scaffolder->addArgs(['Test' => 'String']);
        $scaffolder->addSortableFields(['test']);
        $scaffold = $scaffolder->scaffold($manager = new Manager());
        $manager->addType($o = new ObjectType([
            'name' => 'test',
            'fields' => []
        ]));
        $o->Test = true;
        $config = $scaffold['type']()->config;

        $this->assertEquals('testQueryConnection', $config['name']);
        $this->assertArrayHasKey('pageInfo', $config['fields']);
        $this->assertArrayHasKey('edges', $config['fields']);
    }

    public function testQueryScaffolderApplyConfig()
    {
        $mock = $this->getMockBuilder(QueryScaffolder::class)
            ->setConstructorArgs(['testQuery', 'testType'])
            ->setMethods(['addSortableFields','setUsePagination'])
            ->getMock();
        $mock->expects($this->once())
            ->method('addSortableFields')
            ->with(['Test1','Test2']);
        $mock->expects($this->once())
            ->method('setUsePagination')
            ->with(false);

        $mock->applyConfig([
            'sortableFields' => ['Test1','Test2'],
            'paginate' => false
        ]);
    }

    public function testQueryScaffolderApplyConfigThrowsOnBadSortableFields()
    {
        $scaffolder = new QueryScaffolder('testQuery', 'testType');
        $this->setExpectedExceptionRegExp(
            InvalidArgumentException::class,
            '/sortableFields must be an array/'
        );
        $scaffolder->applyConfig([
            'sortableFields' => 'fail'
        ]);
    }

    public function testCreateOperationResolver()
    {
        $create = new Create(DataObjectFake::class);
        $scaffold = $create->scaffold(new Manager());

        $newRecord = $scaffold['resolve'](
            null,
            [
                'Input' => ['MyField' => '__testing__']
            ],
            [
                'currentUser' => Member::create()
            ],
            new ResolveInfo([])
        );

        $this->assertGreaterThan(0, $newRecord->ID);
        $this->assertEquals('__testing__', $newRecord->MyField);
    }

    public function testCreateOperationInputType()
    {
        $create = new Create(DataObjectFake::class);
        $scaffold = $create->scaffold(new Manager());

        $this->assertArrayHasKey('Input', $scaffold['args']);
        $this->assertInstanceof(NonNull::class, $scaffold['args']['Input']['type']);
        
        $config = $scaffold['args']['Input']['type']->getWrappedType()->config;

        $this->assertEquals('Data_Object_FakeCreateInputType', $config['name']);
        $fieldMap = [];
        foreach ($config['fields'] as $name => $fieldData) {
            $fieldMap[$name] = $fieldData['type'];
        }
        $this->assertArrayHasKey('Created', $fieldMap, 'Includes fixed_fields');
        $this->assertArrayHasKey('MyField', $fieldMap);
        $this->assertArrayHasKey('MyInt', $fieldMap);
        $this->assertArrayNotHasKey('ID', $fieldMap);
        $this->assertInstanceOf(StringType::class, $fieldMap['MyField']);
        $this->assertInstanceOf(IntType::class, $fieldMap['MyInt']);
    }

    public function testCreateOperationPermissionCheck()
    {
        $create = new Create(RestrictedDataObjectFake::class);
        $scaffold = $create->scaffold(new Manager());

        $this->setExpectedExceptionRegExp(
            Exception::class,
            '/Cannot create/'
        );

        $scaffold['resolve'](
            null,
            [],
            ['currentUser' => Member::create()],
            new ResolveInfo([])
        );
    }

    public function testUpdateOperationResolver()
    {
        $update = new Update(DataObjectFake::class);
        $scaffold = $update->scaffold(new Manager());

        $record = DataObjectFake::create([
            'MyField' => 'old'
        ]);
        $ID = $record->write();

        $scaffold['resolve'](
            $record,
            [
                'ID' => $ID,
                'Input' => ['MyField' => 'new']
            ],
            [
                'currentUser' => Member::create()
            ],
            new ResolveInfo([])
        );
        $updatedRecord = DataObjectFake::get()->byID($ID);
        $this->assertEquals('new', $updatedRecord->MyField);
    }

    public function testUpdateOperationInputType()
    {
        $update = new Update(DataObjectFake::class);
        $scaffold = $update->scaffold(new Manager());

        $this->assertArrayHasKey('Input', $scaffold['args']);
        $this->assertInstanceof(NonNull::class, $scaffold['args']['Input']['type']);
        
        $config = $scaffold['args']['Input']['type']->getWrappedType()->config;

        $this->assertEquals('Data_Object_FakeUpdateInputType', $config['name']);
        $fieldMap = [];
        foreach ($config['fields'] as $name => $fieldData) {
            $fieldMap[$name] = $fieldData['type'];
        }
        $this->assertArrayHasKey('Created', $fieldMap, 'Includes fixed_fields');
        $this->assertArrayHasKey('MyField', $fieldMap);
        $this->assertArrayHasKey('MyInt', $fieldMap);
        $this->assertArrayNotHasKey('ID', $fieldMap);
        $this->assertInstanceOf(StringType::class, $fieldMap['MyField']);
        $this->assertInstanceOf(IntType::class, $fieldMap['MyInt']);
    }

    public function testUpdateOperationPermissionCheck()
    {
        $update = new Update(RestrictedDataObjectFake::class);
        $restrictedDataobject = RestrictedDataObjectFake::create();
        $ID = $restrictedDataobject->write();

        $scaffold = $update->scaffold(new Manager());

        $this->setExpectedExceptionRegExp(
            Exception::class,
            '/Cannot edit/'
        );

        $scaffold['resolve'](
            $restrictedDataobject,
            ['ID' => $ID],
            ['currentUser' => Member::create()],
            new ResolveInfo([])
        );
    }


    public function testDeleteOperationResolver()
    {
        $delete = new Delete(DataObjectFake::class);
        $scaffold = $delete->scaffold(new Manager());

        $record = DataObjectFake::create();
        $ID1 = $record->write();

        $record = DataObjectFake::create();
        $ID2 = $record->write();

        $record = DataObjectFake::create();
        $ID3 = $record->write();

        $scaffold['resolve'](
            $record,
            [
                'IDs' => [$ID1, $ID2]
            ],
            [
                'currentUser' => Member::create()
            ],
            new ResolveInfo([])
        );
        
        $this->assertNull(DataObjectFake::get()->byID($ID1));
        $this->assertNull(DataObjectFake::get()->byID($ID2));
        $this->assertInstanceOf(DataObjectFake::class, DataObjectFake::get()->byID($ID3));
    }

    public function testDeleteOperationArgs()
    {
        $delete = new Delete(DataObjectFake::class);
        $scaffold = $delete->scaffold(new Manager());

        $this->assertArrayHasKey('IDs', $scaffold['args']);
        $this->assertInstanceof(NonNull::class, $scaffold['args']['IDs']['type']);
        
        $listOf = $scaffold['args']['IDs']['type']->getWrappedType();

        $this->assertInstanceOf(ListOfType::class, $listOf);

        $idType = $listOf->getWrappedType();

        $this->assertInstanceof(IDType::class, $idType);
    }

    public function testDeleteOperationPermissionCheck()
    {
        $delete = new Delete(RestrictedDataObjectFake::class);
        $restrictedDataobject = RestrictedDataObjectFake::create();
        $ID = $restrictedDataobject->write();

        $scaffold = $delete->scaffold(new Manager());

        $this->setExpectedExceptionRegExp(
            Exception::class,
            '/Cannot delete/'
        );

        $scaffold['resolve'](
            $restrictedDataobject,
            ['IDs' => [$ID]],
            ['currentUser' => Member::create()],
            new ResolveInfo([])
        );
    }

    public function testReadOperationResolver()
    {
        $read = new Read(DataObjectFake::class);
        $scaffold = $read->scaffold(new Manager());

        DataObjectFake::get()->removeAll();
        
        $record = DataObjectFake::create();
        $ID1 = $record->write();

        $record = DataObjectFake::create();
        $ID2 = $record->write();

        $record = DataObjectFake::create();
        $ID3 = $record->write();

        $response = $scaffold['resolve'](
            null,
            [],
            [
                'currentUser' => Member::create()
            ],
            new ResolveInfo([])
        );

        $this->assertArrayHasKey('edges', $response);
        $this->assertEquals([$ID1, $ID2, $ID3], $response['edges']->column('ID'));
    }


    public function testTypeParser()
    {
        $parser = new TypeParser('String!(Test)');
        $this->assertTrue($parser->isRequired());
        $this->assertEquals('String', $parser->getArgTypeName());
        $this->assertEquals('Test', $parser->getDefaultValue());
        $this->assertTrue(is_string($parser->getDefaultValue()));

        $parser = new TypeParser('String! (Test)');
        $this->assertTrue($parser->isRequired());
        $this->assertEquals('String', $parser->getArgTypeName());
        $this->assertEquals('Test', $parser->getDefaultValue());

        $parser = new TypeParser('Int!');
        $this->assertTrue($parser->isRequired());
        $this->assertEquals('Int', $parser->getArgTypeName());
        $this->assertNull($parser->getDefaultValue());

        $parser = new TypeParser('Int!(23)');
        $this->assertTrue($parser->isRequired());
        $this->assertEquals('Int', $parser->getArgTypeName());
        $this->assertEquals('23', $parser->getDefaultValue());
        $this->assertTrue(is_int($parser->getDefaultValue()));

        $parser = new TypeParser('Boolean');
        $this->assertFalse($parser->isRequired());
        $this->assertEquals('Boolean', $parser->getArgTypeName());
        $this->assertNull($parser->getDefaultValue());

        $parser = new TypeParser('Boolean(1)');
        $this->assertFalse($parser->isRequired());
        $this->assertEquals('Boolean', $parser->getArgTypeName());
        $this->assertEquals('1', $parser->getDefaultValue());
        $this->assertTrue(is_bool($parser->getDefaultValue()));

        $parser = new TypeParser('String!(Test)');
        $this->assertInstanceOf(StringType::class, $parser->getType());
        $this->assertEquals('Test', $parser->getDefaultValue());

        $this->setExpectedExceptionRegExp(
            InvalidArgumentException::class,
            '/Invalid argument/'
        );
        
        $parser = new TypeParser('  ... Nothing');

        $this->setExpectedExceptionRegExp(
            InvalidArgumentException::class,
            '/Invalid type/'
        );

        $parser = (new TypeParser('Nothing'))->toArray();
    }

    public function testOperationList()
    {
        $list = new OperationList();

        $list->push(new MutationScaffolder('myMutation1', 'test1'));
        $list->push(new MutationScaffolder('myMutation2', 'test2'));

        $this->assertInstanceOf(
            MutationScaffolder::class,
            $list->findByName('myMutation1')
        );
        $this->assertFalse($list->findByName('myMutation3'));

        $list->removeByName('myMutation2');
        $this->assertEquals(1, $list->count());

        $list->removeByName('nothing');
        $this->assertEquals(1, $list->count());

        $this->setExpectedExceptionRegExp(
            InvalidArgumentException::class,
            '/only accepts instances of/'
        );
        $list->push(new OperationList());
    }

    protected function getFakeScaffolder()
    {
        return new DataObjectScaffolder(DataObjectFake::class);
    }
}
