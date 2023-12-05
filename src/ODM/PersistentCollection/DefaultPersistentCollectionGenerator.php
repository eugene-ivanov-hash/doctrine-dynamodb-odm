<?php

declare(strict_types=1);

namespace Aristek\Bundle\DynamodbBundle\ODM\PersistentCollection;

use ReflectionClass;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Aristek\Bundle\DynamodbBundle\ODM\Configuration;
use function array_map;
use function array_pop;
use function assert;
use function class_exists;
use function dirname;
use function explode;
use function file_exists;
use function file_put_contents;
use function implode;
use function in_array;
use function interface_exists;
use function is_dir;
use function is_writable;
use function mkdir;
use function rename;
use function sprintf;
use function str_replace;
use function strtolower;
use function substr;
use function uniqid;
use function var_export;
use const DIRECTORY_SEPARATOR;

/**
 * Default generator for custom PersistentCollection classes.
 */
final class DefaultPersistentCollectionGenerator implements PersistentCollectionGenerator
{
    /**
     * The directory that contains all persistent collection classes.
     */
    private string $collectionDir;

    /**
     * The namespace that contains all persistent collection classes.
     */
    private string $collectionNamespace;

    public function __construct(string $collectionDir, string $collectionNs)
    {
        $this->collectionDir = $collectionDir;
        $this->collectionNamespace = $collectionNs;
    }

    /**
     * @throws PersistentCollectionException
     * @throws ReflectionException
     */
    public function generateClass(string $class, string $dir): void
    {
        $collClassName = str_replace('\\', '', $class).'Persistent';
        $className = $this->collectionNamespace.'\\'.$collClassName;
        $fileName = $dir.DIRECTORY_SEPARATOR.$collClassName.'.php';
        $this->generateCollectionClass($class, $className, $fileName);
    }

    /**
     * @throws PersistentCollectionException
     * @throws ReflectionException
     * @throws ReflectionException
     * @throws ReflectionException
     */
    public function loadClass(string $collectionClass, int $autoGenerate): string
    {
        // These checks are not in __construct() because of BC and should be moved for 2.0
        if (!$this->collectionDir) {
            throw PersistentCollectionException::directoryRequired();
        }

        if (!$this->collectionNamespace) {
            throw PersistentCollectionException::namespaceRequired();
        }

        $collClassName = str_replace('\\', '', $collectionClass).'Persistent';
        $className = $this->collectionNamespace.'\\'.$collClassName;
        if (!class_exists($className, false)) {
            $fileName = $this->collectionDir.DIRECTORY_SEPARATOR.$collClassName.'.php';
            switch ($autoGenerate) {
                case Configuration::AUTOGENERATE_NEVER:
                    require $fileName;
                    break;

                case Configuration::AUTOGENERATE_ALWAYS:
                    $this->generateCollectionClass($collectionClass, $className, $fileName);
                    require $fileName;
                    break;

                case Configuration::AUTOGENERATE_FILE_NOT_EXISTS:
                    if (!file_exists($fileName)) {
                        $this->generateCollectionClass($collectionClass, $className, $fileName);
                    }

                    require $fileName;
                    break;

                case Configuration::AUTOGENERATE_EVAL:
                    $this->generateCollectionClass($collectionClass, $className, false);
                    break;
            }
        }

        return $className;
    }

    /**
     * @throws ReflectionException
     * @throws PersistentCollectionException
     */
    private function buildParametersString(ReflectionMethod $method): string
    {
        $parameters = $method->getParameters();
        $parameterDefinitions = [];

        foreach ($parameters as $param) {
            $parameterDefinition = '';
            if ($param->hasType()) {
                $parameterDefinition .= $this->getParameterType($param).' ';
            }

            if ($param->isPassedByReference()) {
                $parameterDefinition .= '&';
            }

            if ($param->isVariadic()) {
                $parameterDefinition .= '...';
            }

            $parameters[] = '$'.$param->name;
            $parameterDefinition .= '$'.$param->name;

            if ($param->isDefaultValueAvailable()) {
                $parameterDefinition .= ' = '.var_export($param->getDefaultValue(), true);
            }

            $parameterDefinitions[] = $parameterDefinition;
        }

        return implode(', ', $parameterDefinitions);
    }

    /**
     * @throws PersistentCollectionException
     */
    private function formatType(
        ReflectionType $type,
        ReflectionMethod $method,
        ?ReflectionParameter $parameter = null,
    ): string {
        if ($type instanceof ReflectionUnionType) {
            return implode(
                '|',
                array_map(
                    fn(ReflectionType $unionedType) => $this->formatType($unionedType, $method, $parameter),
                    $type->getTypes(),
                )
            );
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode(
                '&',
                array_map(
                    fn(ReflectionType $intersectedType) => $this->formatType($intersectedType, $method, $parameter),
                    $type->getTypes(),
                )
            );
        }

        assert($type instanceof ReflectionNamedType);
        $name = $type->getName();
        $nameLower = strtolower($name);
        if ($nameLower === 'self') {
            $name = $method->getDeclaringClass()->getName();
        }

        if ($nameLower === 'parent') {
            $parentClass = $method->getDeclaringClass()->getParentClass();
            if (!$parentClass) {
                throw PersistentCollectionException::parentClassRequired(
                    $method->getDeclaringClass()->getName(),
                    $method->getName()
                );
            }

            $name = $parentClass->getName();
        }

        if ($nameLower !== 'static' && !$type->isBuiltin() && !class_exists($name) && !interface_exists($name)) {
            if ($parameter !== null) {
                throw PersistentCollectionException::invalidParameterTypeHint(
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                    $parameter->getName(),
                );
            }

            throw PersistentCollectionException::invalidReturnTypeHint(
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            );
        }

        if ($nameLower !== 'static' && !$type->isBuiltin()) {
            $name = '\\'.$name;
        }

        if (
            $type->allowsNull()
            && ($parameter === null || !$parameter->isDefaultValueAvailable() || $parameter->getDefaultValue() !== null)
            && $name !== 'mixed'
        ) {
            $name = '?'.$name;
        }

        return $name;
    }

    /** @param string|false $fileName Filename to write collection class code or false to eval it.
     * @throws PersistentCollectionException
     * @throws ReflectionException
     * @throws ReflectionException
     */
    private function generateCollectionClass(string $for, string $targetFqcn, $fileName): void
    {
        $exploded = explode('\\', $targetFqcn);
        $class = array_pop($exploded);
        $namespace = implode('\\', $exploded);
        $code = <<<CODE
<?php

namespace $namespace;

use Doctrine\Common\Collections\Collection as BaseCollection;
use Aristek\Bundle\DynamodbBundle\ODM\DocumentManager;
use Aristek\Bundle\DynamodbBundle\ODM\Mapping\ClassMetadata;
use Aristek\Bundle\DynamodbBundle\ODM\DynamoDBException;
use Aristek\Bundle\DynamodbBundle\ODM\UnitOfWork;

/**
 * DO NOT EDIT THIS FILE - IT WAS CREATED BY DOCTRINE\'S PERSISTENT COLLECTION GENERATOR
 */
class $class extends \\$for implements \\Aristek\\Bundle\\DynamodbBundle\\ODM\\PersistentCollection\\PersistentCollectionInterface
{
    use \\Aristek\\Bundle\\DynamodbBundle\\ODM\\PersistentCollection\\PersistentCollectionTrait;

    /**
     * @param BaseCollection \$coll
     * @param DocumentManager \$dm
     * @param UnitOfWork \$uow
     */
    public function __construct(BaseCollection \$coll, DocumentManager \$dm, UnitOfWork \$uow)
    {
        \$this->coll = \$coll;
        \$this->dm = \$dm;
        \$this->uow = \$uow;
    }

CODE;
        $rc = new ReflectionClass($for);
        $rt = new ReflectionClass(PersistentCollectionTrait::class);
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (
                $rt->hasMethod($method->name) ||
                $method->isConstructor() ||
                $method->isFinal() ||
                $method->isStatic()
            ) {
                continue;
            }

            $code .= $this->generateMethod($method);
        }

        $code .= "}\n";

        if ($fileName === false) {
            if (!class_exists($targetFqcn)) {
                eval(substr($code, 5));
            }
        } else {
            $parentDirectory = dirname($fileName);

            if (!is_dir($parentDirectory) && (@mkdir($parentDirectory, 0775, true) === false)) {
                throw PersistentCollectionException::directoryNotWritable();
            }

            if (!is_writable($parentDirectory)) {
                throw PersistentCollectionException::directoryNotWritable();
            }

            $tmpFileName = $fileName.'.'.uniqid('', true);
            file_put_contents($tmpFileName, $code);
            rename($tmpFileName, $fileName);
        }
    }

    /**
     * @throws ReflectionException
     * @throws PersistentCollectionException
     */
    private function generateMethod(ReflectionMethod $method): string
    {
        $parametersString = $this->buildParametersString($method);
        $callParamsString = implode(', ', $this->getParameterNamesForDecoratedCall($method->getParameters()));
        $return = $this->shouldMethodSkipReturnKeyword($method) ? '' : 'return ';

        return <<<CODE

    public function $method->name($parametersString){$this->getMethodReturnType($method)}
    {
        \$this->initialize();
        if (\$this->needsSchedulingForSynchronization()) {
            \$this->changed();
        }
        $return\$this->coll->$method->name($callParamsString);
    }

CODE;
    }

    /**
     * @throws PersistentCollectionException
     */
    private function getMethodReturnType(ReflectionMethod $method): string
    {
        $returnType = $method->getReturnType();
        if ($returnType === null) {
            return '';
        }

        return ': '.$this->formatType($returnType, $method);
    }

    /**
     * @param ReflectionParameter[] $parameters
     *
     * @return string[]
     */
    private function getParameterNamesForDecoratedCall(array $parameters): array
    {
        return array_map(
            static function (ReflectionParameter $parameter) {
                $name = '';

                if ($parameter->isVariadic()) {
                    $name .= '...';
                }

                return $name.'$'.$parameter->name;
            },
            $parameters,
        );
    }

    /**
     * @throws ReflectionException
     * @throws PersistentCollectionException
     */
    private function getParameterType(ReflectionParameter $parameter): string
    {
        if (!$parameter->hasType()) {
            throw new ReflectionException(
                sprintf('Parameter "%s" has no type. Please file a bug report.', $parameter->getName())
            );
        }

        $method = $parameter->getDeclaringFunction();
        assert($method instanceof ReflectionMethod);

        return $this->formatType($parameter->getType(), $method, $parameter);
    }

    /**
     * @throws PersistentCollectionException
     */
    private function shouldMethodSkipReturnKeyword(ReflectionMethod $method): bool
    {
        if ($method->getReturnType() === null) {
            return false;
        }

        return in_array($this->formatType($method->getReturnType(), $method), ['void', 'never'], true);
    }
}
