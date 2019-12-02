<?php

declare(strict_types=1);

namespace Randock\Graphql\Generator;

use Softonic\GraphQL\Client;
use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Parameter;
use Nette\PhpGenerator\PsrPrinter;
use Randock\Graphql\Generator\Base\ApiClient;
use Randock\Graphql\Generator\Base\AbstractEnum;
use Randock\Graphql\Generator\Base\AbstractInput;
use Randock\Graphql\Generator\Base\AbstractModel;

class ModelGenerator
{
    /**
     * @var Client
     */
    private $graphqlClient;

    /**
     * @var SchemaIntrospector
     */
    private $introspector;

    /**
     * ModelGenerator constructor.
     *
     * @param Client             $graphqlClient
     * @param SchemaIntrospector $introspector
     */
    public function __construct(Client $graphqlClient, SchemaIntrospector $introspector)
    {
        $this->graphqlClient = $graphqlClient;
        $this->introspector = $introspector;
    }

    public function generateObjects(string $namespace, string $baseDir)
    {
        $classmap = $this->getClassmap($namespace);

        $this->generateQuery($namespace, $classmap, $baseDir);

        $this->generateModels($namespace, $classmap, $baseDir);
        $this->generateEnums($namespace, $classmap, $baseDir);
        $this->generateInputs($namespace, $classmap, $baseDir);
    }

    /**
     * @param array $dataArray : The subarray which contains the key "type"
     *
     * @return array : Array formatted as [$typeName, $typeKind, $typeKindWrappers]
     */
    protected function getTypeInfo(array $dataArray): array
    {
        $typeArray = $dataArray['type'];
        $typeWrappers = [];
        while (null !== $typeArray['ofType']) {
            $typeWrappers[] = $typeArray['kind'];
            $typeArray = $typeArray['ofType'];
        }
        $typeInfo = [$typeArray['name'], $typeArray['kind'], $typeWrappers];

        return $typeInfo;
    }

    private function generateQuery(string $baseNamespace, array $classmap, string $baseDir)
    {
        $imports = [];

        $query = $this->introspector->getQuery();

        $class = new ClassType('ApiClient');
        $class->addExtend(ApiClient::class);

        foreach ($query['fields'] as $field) {
            [$convertedField] = $parsed = $this->convertFields([$field]);

            // import
            if (isset($classmap[$convertedField['kind']])) {
                $imports[] = $classmap[$convertedField['kind']];
            }

            // create method
            $method = new Method($convertedField['name']);
            $method->setReturnType(
                $classmap[$convertedField['returnType']] ?? $convertedField['returnType']
            );

            $args = $this->convertFields($field['args']);

            // add fields argument
            $method->addParameter('fields')->setType('array');
            $method->addComment('@param array $fields');

            foreach ($args as $arg) {
                $parameter = $method->addParameter($arg['name']);

                if ('array' === $arg['returnType'] && true === $arg['nullable']) {
                    $parameter->setDefaultValue([]);
                } else {
                    $parameter->setNullable($arg['nullable']);
                }

                $parameter->setType(
                    $classmap[$arg['returnType']] ?? $arg['returnType']
                );

                $method->addComment(
                    \sprintf(
                        '@param %s $%s',
                        $arg['doc'],
                        $arg['name']
                    )
                );

                // import
                if (isset($classmap[$arg['kind']])) {
                    $imports[] = $classmap[$arg['kind']];
                }
            }

            $method->addComment('');
            $method->addComment('@return ' . $convertedField['doc']);
            $method->setVisibility('public');

            // body
            $body = <<<'GRAPHQL'
query %1$s(%2$s){
    %1$s(%3$s) {
        %4$s
    }
}
GRAPHQL;

            $return = \sprintf(
                '$data = $response->getData()[\'%s\'];',
                $convertedField['name']
            );

            if ('array' === $convertedField['returnType']) {
                $return .= \sprintf(
                    "\n\n" .
                    'return array_map(function(array $item) {' . "\n"
                        . "\t" . 'return %s::fromArray($item);' . "\n"
                    . '}, $data);',
                    $convertedField['kind']
                );
            } elseif ('OBJECT' === $convertedField['type']) {
                $return .= \sprintf(
                    "\n\n" . 'return %s::fromArray($data);',
                    $convertedField['kind']
                );
            } else {
                $return .= "\n\n" . 'return $data;';
            }

            $graphqlSyntax = [];
            foreach ($args as $arg) {
                $returnType = \ucfirst($arg['returnType']);
                if ('Array' === $returnType) {
                    $returnType = \sprintf(
                        '[%s]',
                        $arg['kind']
                    );
                }
                if (!$arg['nullable']) {
                    $returnType .= '!';
                }
                $graphqlSyntax[$arg['name']] = $returnType;
            }

            $variables = '';
            $graphqlVariablesMethod = '';
            $graphqlVariablesCall = '';

            foreach ($args as $arg) {
                $variables .= \sprintf(
                    ', \'%1$s\' => $this->convertInput($%1$s)',
                    $arg['name']
                );

                $graphqlVariablesMethod .= \sprintf(
                    ', $%s: %s',
                    $arg['name'],
                    $graphqlSyntax[$arg['name']]
                );

                $graphqlVariablesCall .= \sprintf(
                    ', %1$s: $%1$s',
                    $arg['name']
                );
            }
            $variables = \substr($variables, 2);
            $graphqlVariablesMethod = \substr($graphqlVariablesMethod, 2);
            $graphqlVariablesCall = \substr($graphqlVariablesCall, 2);

            $method->addBody(
                \sprintf(
                    \sprintf(
                        '$query = \'%s\';' . "\n\n"
                        . '$response = $this->query(' . "\n\t" . 'sprintf($query, $this->convertFields($fields)),' . "\n\t" . '[%s]' . "\n" . ');' . "\n"
                        . '%s',
                        $body,
                        $variables,
                        $return
                    ),
                    $convertedField['name'],
                    $graphqlVariablesMethod,
                    $graphqlVariablesCall,
                    '%s'
                )
            );

            $class->addMember($method);
        }

        $file = new PhpFile();
        $file->setStrictTypes(true);

        $namespace = $file->addNamespace(
            $baseNamespace
        );
        $namespace->addUse(
            ApiClient::class,
            'BaseApiClient'
        );

        foreach ($imports as $import) {
            $namespace->addUse($import);
        }
        $namespace->add($class);

        $printer = new PsrPrinter();
        \file_put_contents(
            \sprintf(
                '%s/ApiClient.php',
                $baseDir
            ),
            $printer->printFile($file)
        );
    }

    private function generateInputs(string $baseNamespace, array $classmap, string $baseDir)
    {
        $printer = new PsrPrinter();
        foreach ($this->introspector->getTypes('INPUT_OBJECT') as $object) {
            [$class, $imports] = $this->generateInputClass($object, $classmap);

            $file = new PhpFile();
            $file->setStrictTypes(true);

            $namespace = $file->addNamespace(
                \sprintf(
                    '%s\Object\Input',
                    $baseNamespace
                )
            );
            foreach ($imports as $import) {
                $namespace->addUse($import);
            }
            $namespace->addUse(AbstractInput::class);
            $namespace->add($class);

            \file_put_contents(
                \sprintf(
                    '%s/Object/Input/%s.php',
                    $baseDir,
                    $object
                ),
                $printer->printFile($file)
            );
        }
    }

    private function generateEnums(string $baseNamespace, array $classmap, string $baseDir)
    {
        $printer = new PsrPrinter();
        foreach ($this->introspector->getTypes('ENUM') as $object) {
            $class = $this->generateEnumClass($object, $baseNamespace, $classmap);

            $file = new PhpFile();
            $file->setStrictTypes(true);

            $namespace = $file->addNamespace(
                \sprintf(
                    '%s\Object\Enum',
                    $baseNamespace
                )
            );
            $namespace->addUse(AbstractEnum::class);
            $namespace->add($class);

            \file_put_contents(
                \sprintf(
                    '%s/Object/Enum/%s.php',
                    $baseDir,
                    $object
                ),
                $printer->printFile($file)
            );
        }
    }

    private function getClassmap(string $namespace): array
    {
        $map = [];
        foreach ($this->introspector->getTypes('OBJECT') as $object) {
            $map[$object] = \sprintf(
                '%s\Object\Model\%s',
                $namespace,
                $object
            );
        }
        foreach ($this->introspector->getTypes('INPUT_OBJECT') as $object) {
            $map[$object] = \sprintf(
                '%s\Object\Input\%s',
                $namespace,
                $object
            );
        }
        foreach ($this->introspector->getTypes('ENUM') as $object) {
            $map[$object] = \sprintf(
                '%s\Object\Enum\%s',
                $namespace,
                $object
            );
        }

        return $map;
    }

    private function generateModels(string $baseNamespace, array $classmap, string $baseDir)
    {
        $printer = new PsrPrinter();
        foreach ($this->introspector->getTypes('OBJECT') as $object) {
            $modelNamespace = \sprintf(
                '%s\Object\Model',
                $baseNamespace
            );

            [$class, $imports] = $this->generateModelClass(
                $object,
                $classmap
            );

            $file = new PhpFile();
            $file->setStrictTypes(true);

            $namespace = $file->addNamespace(
                $modelNamespace
            );
            $namespace->addUse(AbstractModel::class);
            foreach ($imports as $import) {
                $namespace->addUse($import);
            }
            $namespace->add($class);

            \file_put_contents(
                \sprintf(
                    '%s/Object/Model/%s.php',
                    $baseDir,
                    $object
                ),
                $printer->printFile($file)
            );
        }
    }

    private function generateModelClass(
        string $object,
        array $classmap
    ): array {
        $imports = [];
        $class = new ClassType($object);
        $class->addExtend(AbstractModel::class);

        $fieldData = $this->introspector->getObject($object);
        $fields = $fieldData['fields'];

        $fields = $this->convertFields($fields);

        // add constructor
        $class->addMember(
            $this->generatedFactoryCreate($fields)
        );

        // add getters
        foreach ($fields as $field) {
            [$method, $import] = $this->generateGetter($classmap, $field);
            $class->addMember(
                $method
            );
            $imports = \array_merge($imports, $import);
        }

        return [$class, $imports];
    }

    private function generateInputClass(
        string $object,
        array $classmap
    ): array {
        $imports = [];
        $class = new ClassType($object);
        $class->addExtend(AbstractInput::class);

        $fieldData = $this->introspector->getInputObject($object);
        $fields = $fieldData['inputFields'];

        $fields = $this->convertFields($fields);

        [$method, $import] = $this->generateConstructor($classmap, $fields);
        $imports = \array_merge($imports, $import);
        $class->addMember($method);

        // add getters
        foreach ($fields as $field) {
            [$method, $import] = $this->generateGetter($classmap, $field);
            $class->addMember(
                $method
            );
            $imports = \array_merge($imports, $import);
        }

        return [$class, $imports];
    }

    /**
     * @param array $classmap
     * @param array $field
     *
     * @return array
     */
    private function generateGetter(array $classmap, array $field): array
    {
        $imports = [];

        $returnType = $field['returnType'];
        if (isset($classmap[$returnType])) {
            $returnType = $classmap[$returnType];
            $imports[] = $returnType;
        }
        if (isset($classmap[$field['kind']])) {
            $imports[] = $classmap[$field['kind']];
        }

        $method = $this->generateGetMethod(
            $field['name'],
            $returnType,
            $field['doc'],
            $field['nullable']
        );

        return [$method, $imports];
    }

    private function convertFields(array $schemaFields): array
    {
        $fields = [];
        foreach ($schemaFields as $field) {
            $name = $field['name'];
            $nullable = 'NON_NULL' !== $field['type']['kind'];

            [$typeName, $typeKind] = $this->getTypeInfo($field);

            $returnType = null;
            $doc = null;

            switch ($typeKind) {
                case 'SCALAR':
                    switch ($typeName) {
                        case 'ID':
                        case 'String':
                            $returnType = 'string';
                            $doc = 'string';
                            break;
                        case 'Int':
                            $returnType = 'int';
                            $doc = 'int';
                            break;
                        case 'Date':
                        case 'DateTime':
                            $returnType = 'DateTime';
                            $doc = '\DateTime';
                            break;
                        case 'Float':
                            $returnType = 'float';
                            $doc = 'float';
                            break;
                        case 'Boolean':
                            $returnType = 'bool';
                            $doc = 'bool';
                            break;
                        case 'SimpleObject':
                            $returnType = 'object';
                            $doc = 'object';
                            break;
                        case 'Iterable':
                            $returnType = 'iterable';
                            $doc = 'iterable';
                            break;
                    }
                    break;
                case 'ENUM':
                    $returnType = $typeName;
                    $doc = $typeName;
                    break;
                case 'OBJECT':
                case 'INPUT_OBJECT':
                    if ('LIST' === $field['type']['kind']) {
                        $returnType = 'array';
                        $doc = $typeName . '[]';
                    } else {
                        $returnType = $typeName;
                        $doc = $typeName;
                    }
                    break;
            }

            $fields[] = [
                'array' => 'LIST' === $field['type']['kind'],
                'type' => $typeKind,
                'nullable' => $nullable,
                'name' => $field['name'],
                'returnType' => $returnType,
                'doc' => $doc,
                'kind' => $typeName,
            ];
        }

        return $fields;
    }

    private function generateEnumClass(string $object, string $namesapce, array $classmap): ClassType
    {
        $enum = $this->introspector->getEnumObject($object);

        $class = new ClassType($object);
        foreach ($enum['enumValues'] as $value) {
            $class->addConstant(
                \strtoupper($value['name']),
                $value['name']
            )->setVisibility('public');
        }

        $class->addExtend(AbstractEnum::class);

        return $class;
    }

    private function generateConstructor(array $classmap, array $fields): array
    {
        $imports = [];

        $method = new Method(
            '__construct'
        );
        $body = '$data = [];';

        $parameters = [];
        foreach ($fields as $field) {
            $returnType = $field['returnType'];
            if (isset($classmap[$returnType])) {
                $returnType = $classmap[$returnType];
                $imports[] = $returnType;
            }

            $parameter = new Parameter($field['name']);
            $parameter->setType($returnType);
            $parameter->setNullable($field['nullable']);
            $parameters[] = $parameter;

            $body .= \sprintf(
                "\n" . '$data[\'%1$s\'] = $%1$s;',
                $field['name']
            );
        }

        $body .= "\n\n" . 'parent::__construct($data);';

        $method->setVisibility('public')->setParameters(
            $parameters
        )->setBody($body);

        return [$method, $imports];
    }

    private function generatedFactoryCreate(array $fields): Method
    {
        $method = new Method(
            'fromArray'
        );
        $method->setStatic(true);
        $method->setVisibility('public');
        $method->setReturnType('self');
        $method->addComment(
            '@param array $data'
        );
        $method->addComment('');
        $method->addComment(
            '@return self'
        );

        $method->setParameters(
            [
                (new Parameter('data'))->setType('array'),
            ]
        );

        $body = '';
        foreach ($fields as $field) {
            if ('OBJECT' === $field['type']) {
                if ($field['array']) {
                    $body .= \sprintf(
                        "\n" . 'if(isset($data[\'%1$s\'])) {' . "\n" . '$array = [];' . "\n" . 'foreach($data[\'%1$s\'] as $item) {' . "\n",
                        $field['name']
                    );
                    $body .= \sprintf(
                        '    $array[] = %s::fromArray($item);',
                        $field['kind']
                    ) . "\n";
                    $body .= "}\n";
                    $body .= \sprintf(
                        '$data[\'%s\'] = $array;' . "\n}",
                        $field['name']
                    );
                    $body .= "\n";
                } else {
                    $body .= \sprintf(
                        '$data[\'%s\'] = isset($data[\'%s\']) ? %s::fromArray($data[\'%s\']) : null;',
                        $field['name'],
                        $field['name'],
                        $field['kind'],
                        $field['name']
                    ) . "\n";
                }
            }
        }
        if (!empty($body)) {
            $body .= "\n";
        }

        // add construct
        $body .= 'return new self($data);';
        $method->setBody($body);

        return $method;
    }

    /**
     * @param string $name
     * @param string $returnType
     * @param string $doc
     * @param bool   $nullable
     *
     * @return Method
     */
    private function generateGetMethod(string $name, string $returnType, string $doc, bool $nullable): Method
    {
        $doc = \sprintf(
            '%s%s',
            $doc,
            $nullable ? '|null' : ''
        );

        $method = new Method(
            \sprintf(
                'get%s',
                \ucfirst($name)
            )
        );
        $method->setReturnType(
            $returnType
        );
        $method->setReturnNullable(
            $nullable
        );
        $method->addComment(
            \sprintf(
                '@return %s',
                $doc
            )
        );

        switch ($returnType) {
            case 'DateTime':
                $body = <<<'CODE'
$value = $this->_getField('%s', %s);
if (null !== $value) {
    $value = new \DateTime($value);
}

return $value;
CODE;
                $body = \sprintf(
                    $body,
                    $name,
                    ($nullable ? 'true' : 'false')
                );
                break;
            default:
            $body = <<<'CODE'
/** @var %s $value */
$value = $this->_getField('%s', %s);

return $value;
CODE;
            $body = \sprintf(
                $body,
                $doc,
                $name,
                ($nullable ? 'true' : 'false')
            );
            break;
        }

        $method->setBody(
            $body
        );

        return $method;
    }
}
