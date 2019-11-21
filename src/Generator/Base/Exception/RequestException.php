<?php

declare(strict_types=1);

namespace Randock\Graphql\Generator\Base\Exception;

class RequestException extends \Exception
{
    /**
     * @var array
     */
    private $errors;

    /**
     * RequestException constructor.
     *
     * @param array $errors
     */
    public function __construct(array $errors)
    {
        parent::__construct(
            \sprintf(
                'Invalid request. Got %d errors. First error message is: %s',
                \count($errors),
                $errors[0]['message']
            )
        );
        $this->errors = $errors;
    }
}
