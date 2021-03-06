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
     * @var array
     */
    private $interfaces;

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

        $this->generateClient($namespace, $classmap, $baseDir);

        $this->generateUnions($namespace, $classmap, $baseDir);
        $this->generateInterfaces($namespace, $classmap, $baseDir);

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
        $typeWrappers = [];

        $type = $dataArray['type'];
        while (isset($type['ofType'])) {
            $typeWrappers[] = $type['kind'];
            $type = $type['ofType'];
        }

        return [
            'field' => $dataArray['name'],
            'nullable' => 'NON_NULL' !== $dataArray['type']['kind'],
            'array' => \in_array('LIST', $typeWrappers),
            'kind' => $type['kind'],
            'name' => $type['name'],
        ];
    }

    private function generateClient(string $baseNamespace, array $classmap, string $baseDir)
    {
        $class = new ClassType('ApiClient');
        $class->addExtend(ApiClient::class);

        $imports = [];

        foreach (['query', 'mutation'] as $type) {
            $query = $this->introspector->getQuery($type);

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

                if (\count($args) > 0) {
                    foreach ($args as $arg) {
                        $parameter = $method->addParameter($arg['name']);

                        if (true === $arg['nullable']) {
                            $parameter->setNullable(true);
                            if ('array' === $arg['returnType']) {
                                $parameter->setDefaultValue([]);
                            } else {
                                $parameter->setDefaultValue(null);
                            }
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
                    // body
                    $body = <<<'GRAPHQL'
%5$s %1$s(%2$s){
    %1$s(%3$s) {
        %4$s
    }
}
GRAPHQL;
                } else {
                    // body
                    $body = <<<'GRAPHQL'
%5$s %1$s{
    %1$s {
        %4$s
    }
}
GRAPHQL;
                }

                $method->addComment('');
                $method->addComment('@return ' . $convertedField['doc']);
                $method->setVisibility('public');

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
                } elseif ('UNION' === $convertedField['type']) {
                    $return .= <<<'CODE'

    /** @var callable $callable */
    $callable = [
        __NAMESPACE__ . '\\Object\\Model\\' . $data['__typename'],
        'fromArray'
    ];
    return call_user_func(
        $callable,
        $data
    );
CODE;
                } else {
                    $return .= "\n\n" . 'return $data;';
                }

                $graphqlSyntax = [];
                foreach ($args as $arg) {
                    $returnType = $arg['returnType'];
                    if ('array' === $returnType) {
                        $returnType = \sprintf(
                            '[%s]',
                            $arg['kind']
                        );
                    } elseif ('ID' === $arg['kind']) {
                        $returnType = 'ID';
                    } else {
                        $returnType = $arg['kind'];
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
                            . '$response = $this->query(' . "\n\t" . 'sprintf($query, $this->convertFields($fields)),' . "\n\t"
                            . '[%s]' . "\n" . ');' . "\n"
                            . '%s',
                            $body,
                            $variables,
                            $return
                        ),
                        $convertedField['name'],
                        $graphqlVariablesMethod,
                        $graphqlVariablesCall,
                        '%s',
                        $type
                    )
                );

                $class->addMember($method);
            }
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
        foreach ($this->introspector->getTypes('UNION') as $object) {
            $map[$object] = \sprintf(
                '%s\Object\Union\%s',
                $namespace,
                $object
            );
        }
        foreach ($this->introspector->getTypes('INTERFACE') as $object) {
            $map[$object] = \sprintf(
                '%s\Object\Interfaces\%s',
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

    private function generateUnions(string $baseNamespace, array $classmap, string $baseDir)
    {
        $printer = new PsrPrinter();
        foreach ($this->introspector->getTypes('UNION') as $object) {
            $modelNamespace = \sprintf(
                '%s\Object\Union',
                $baseNamespace
            );

            [$class, $imports] = $this->generateInterface(
                $object,
                $baseNamespace,
                $classmap
            );

            $file = new PhpFile();
            $file->setStrictTypes(true);

            $namespace = $file->addNamespace(
                $modelNamespace
            );
            $namespace->add($class);

            \file_put_contents(
                \sprintf(
                    '%s/Object/Union/%s.php',
                    $baseDir,
                    $object
                ),
                $printer->printFile($file)
            );
        }
    }

    private function generateInterfaces(string $baseNamespace, array $classmap, string $baseDir)
    {
        $printer = new PsrPrinter();
        foreach ($this->introspector->getTypes('INTERFACE') as $object) {
            $modelNamespace = \sprintf(
                '%s\Object\Interfaces',
                $baseNamespace
            );

            [$class, $imports] = $this->generateInterface(
                $object,
                $baseNamespace,
                $classmap
            );

            $file = new PhpFile();
            $file->setStrictTypes(true);

            $namespace = $file->addNamespace(
                $modelNamespace
            );
            foreach ($imports as $import) {
                $namespace->addUse($import);
            }
            $namespace->add($class);

            \file_put_contents(
                \sprintf(
                    '%s/Object/Interfaces/%s.php',
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

        if (isset($this->interfaces[$object])) {
            foreach ($this->interfaces[$object] as $interface) {
                $class->addImplement($classmap[$interface]);
                $imports[] = $classmap[$interface];
            }
        }

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
            $field['nullable'],
            $field['type']
        );

        return [$method, $imports];
    }

    private function convertFields(array $schemaFields): array
    {
        $fields = [];
        foreach ($schemaFields as $field) {
            $typeInfo = $this->getTypeInfo($field);

            $returnType = null;
            $doc = null;

            switch ($typeInfo['kind']) {
                case 'SCALAR':
                    switch ($typeInfo['name']) {
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
                        case 'JSONObject':
                        case 'JSON':
                            $returnType = 'object';
                            $doc = 'object';
                            break;
                        case 'Iterable':
                            $returnType = 'iterable';
                            $doc = 'iterable';
                            break;
                    }
                    if ($typeInfo['array']) {
                        $doc = $returnType . '[]';
                        $returnType = 'array';
                    }
                    break;
                case 'ENUM':
                    $returnType = $typeInfo['name'];
                    $doc = $typeInfo['name'];
                    break;
                case 'INTERFACE':
                case 'OBJECT':
                case 'UNION':
                case 'INPUT_OBJECT':
                    if ($typeInfo['array']) {
                        $returnType = 'array';
                        $doc = $typeInfo['name'] . '[]';
                    } else {
                        $returnType = $typeInfo['name'];
                        $doc = $typeInfo['name'];
                    }
                    break;
            }

            $fields[] = [
                'array' => $typeInfo['array'],
                'type' => $typeInfo['kind'],
                'nullable' => $typeInfo['nullable'],
                'name' => $typeInfo['field'],
                'returnType' => $returnType,
                'doc' => $doc,
                'kind' => $typeInfo['name'],
            ];
        }

        return $fields;
    }

    private function generateEnumClass(string $object, string $namespace, array $classmap): ClassType
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

    private function generateInterface(string $object, string $namespace, array $classmap): array
    {
        $imports = [];

        $class = new ClassType($object);
        $class->setType(ClassType::TYPE_INTERFACE);

        $fieldData = $this->introspector->getObject($object);
        foreach ($fieldData['possibleTypes'] as $possibleType) {
            $this->interfaces[$possibleType['name']][] = $fieldData['name'];
        }

        $fields = $fieldData['fields'];
        if (null !== $fields) {
            $fields = $this->convertFields($fields);

            // add getters
            foreach ($fields as $field) {
                [$method, $import] = $this->generateGetter($classmap, $field);
                $class->addMember(
                    $method
                );
                $imports = \array_merge($imports, $import);
            }
        }

        return [$class, $imports];
    }

    private function generateConstructor(array $classmap, array $fields): array
    {
        $imports = [];

        $method = new Method(
            '__construct'
        );
        $body = '$__data = [];';

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
                "\n" . '$__data[\'%1$s\'] = $%1$s;',
                $field['name']
            );
        }

        $body .= "\n\n" . 'parent::__construct($__data);';

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
            '@param array|null $data'
        );
        $method->addComment('');
        $method->addComment(
            '@return self'
        );

        $method->setParameters(
            [
                (new Parameter('data'))->setType('array')->setNullable(true),
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
     * @param string $type
     *
     * @return Method
     */
    private function generateGetMethod(string $name, string $returnType, string $doc, bool $nullable, string $type): Method
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

                if ('ENUM' === $type) {
                    $body = <<<'CODE'
$value = $this->_getField('%s', %s);
if (null !== $value) {
    $value = new %s($value);
}

return $value;
CODE;
                    $body = \sprintf(
                        $body,
                        $name,
                        ($nullable ? 'true' : 'false'),
                        \substr(
                            $returnType,
                            \strrpos(
                                $returnType,
                                '\\'
                            ) + 1
                        )
                    );
                } elseif ('SCALAR' === $type && 'object' === $returnType) {
                    $body = <<<'CODE'
/** @var %s $value */
$value = $this->_getField('%s', %s);
if (null !== $value) {
    $value = json_decode(json_encode($value));
}

return $value;
CODE;
                    $body = \sprintf(
                        $body,
                        $doc,
                        $name,
                        ($nullable ? 'true' : 'false')
                    );
                } elseif ('UNION' === $type) {
                    $body = <<<'CODE'
/** @var array|null */
$value = $this->_getField('%s', %s);
if (null === $value) {
    return null;
}

/** @var callable $callable */
$callable = [
    __NAMESPACE__ . '\\' . $value['__typename'],
    'fromArray'
];

//  return instance of union
return call_user_func(
    $callable,
    $value
);
CODE;

                    $body = \sprintf(
                        $body,
                        $name,
                        ($nullable ? 'true' : 'false'),
                        $doc
                    );
                } else {
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
                }
                break;
        }

        $method->setBody(
            $body
        );

        return $method;
    }
}
