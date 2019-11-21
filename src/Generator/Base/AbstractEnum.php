<?php

declare(strict_types=1);

namespace Randock\Graphql\Generator\Base;

class AbstractEnum
{
    /**
     * @var string
     */
    protected $value;

    /**
     * AbstractEnum constructor.
     *
     * @param string $value
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(string $value)
    {
        $class = new \ReflectionClass($this);
        if (!\in_array($value, \array_values($class->getConstants()))) {
            throw new \InvalidArgumentException(
                \sprintf(
                    'Invalid argument for %s. Only the following values are allowed: %s',
                    \get_class($this),
                    \implode(
                        ', ',
                        \array_values($class->getConstants())
                    )
                )
            );
        }

        $this->value = $value;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->value;
    }

    /**
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }
}
