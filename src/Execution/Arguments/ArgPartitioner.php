<?php

namespace Nuwave\Lighthouse\Execution\Arguments;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Nuwave\Lighthouse\Support\Contracts\ArgResolver;
use Nuwave\Lighthouse\Support\Utils;
use ReflectionClass;
use ReflectionNamedType;

class ArgPartitioner
{
    /**
     * Partition the arguments into nested and regular.
     *
     * @param  \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet  $argumentSet
     * @return \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet[]
     */
    public static function nestedArgResolvers(ArgumentSet $argumentSet, $root): array
    {
        $model = $root instanceof Model
            ? new \ReflectionClass($root)
            : null;

        foreach ($argumentSet->arguments as $name => $argument) {
            static::attachNestedArgResolver($name, $argument, $model);
        }

        return static::partition(
            $argumentSet,
            static function (string $name, Argument $argument): bool {
                return isset($argument->resolver);
            }
        );
    }

    /**
     * Extract all the arguments that correspond to a relation of a certain type on the model.
     *
     * For example, if the args input looks like this:
     *
     * [
     *  'name' => 'Ralf',
     *  'comments' =>
     *    ['foo' => 'Bar'],
     * ]
     *
     * and the model has a method "comments" that returns a HasMany relationship,
     * the result will be:
     * [
     *   [
     *    'comments' =>
     *      ['foo' => 'Bar'],
     *   ],
     *   [
     *    'name' => 'Ralf',
     *   ]
     * ]
     *
     * @param  \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet  $argumentSet
     * @return \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet[]
     */
    public static function relationMethods(
        ArgumentSet $argumentSet,
        Model $model,
        string $relationClass
    ): array {
        $modelReflection = new ReflectionClass($model);

        return static::partition(
            $argumentSet,
            static function (string $name) use ($modelReflection, $relationClass): bool {
                return static::methodReturnsRelation($modelReflection, $name, $relationClass);
            }
        );
    }

    /**
     * Attach a nested argument resolver to an argument.
     *
     * @param  \Nuwave\Lighthouse\Execution\Arguments\Argument  $argument
     */
    protected static function attachNestedArgResolver(string $name, Argument &$argument, ?ReflectionClass $model): void
    {
        $resolverDirective = $argument->directives->first(
            Utils::instanceofMatcher(ArgResolver::class)
        );

        if ($resolverDirective) {
            $argument->resolver = $resolverDirective;

            return;
        }

        if (isset($model)) {
            $isRelation = static function (string $relationClass) use ($model, $name) {
                return static::methodReturnsRelation($model, $name, $relationClass);
            };

            if (
                $isRelation(HasOne::class)
                || $isRelation(MorphOne::class)
            ) {
                $argument->resolver = new ResolveNested(new NestedOneToOne($name));

                return;
            }

            if (
                $isRelation(HasMany::class)
                || $isRelation(MorphMany::class)
            ) {
                $argument->resolver = new ResolveNested(new NestedOneToMany($name));

                return;
            }

            if (
                $isRelation(BelongsToMany::class)
                || $isRelation(MorphToMany::class)
            ) {
                $argument->resolver = new ResolveNested(new NestedManyToMany($name));

                return;
            }
        }
    }

    /**
     * Partition arguments based on a predicate.
     *
     * The predicate will be called for each argument within the ArgumentSet
     * with the following parameters:
     * 1. The name of the argument
     * 2. The argument itself
     *
     * Returns an array of two new ArgumentSet instances:
     * - the first one contains all arguments for which the predicate matched
     * - the second one contains all arguments for which the predicate did not match
     *
     * @param  \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet  $argumentSet
     * @return \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet[]
     */
    public static function partition(ArgumentSet $argumentSet, \Closure $predicate): array
    {
        $matched = new ArgumentSet();
        $notMatched = new ArgumentSet();

        foreach ($argumentSet->arguments as $name => $argument) {
            if ($predicate($name, $argument)) {
                $matched->arguments[$name] = $argument;
            } else {
                $notMatched->arguments[$name] = $argument;
            }
        }

        return [
            $matched,
            $notMatched,
        ];
    }

    /**
     * Does a method on the model return a relation of the given class?
     */
    public static function methodReturnsRelation(
        ReflectionClass $modelReflection,
        string $name,
        string $relationClass
    ): bool {
        if (! $modelReflection->hasMethod($name)) {
            return false;
        }

        $relationMethodCandidate = $modelReflection->getMethod($name);
        if (! $returnType = $relationMethodCandidate->getReturnType()) {
            return false;
        }

        if (! $returnType instanceof ReflectionNamedType) {
            return false;
        }

        return is_a($returnType->getName(), $relationClass, true);
    }
}
