<?php

namespace Exonn\ScrambleSpatieQueryBuilder;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Infer\Reflector\ClassReflector;
use Dedoc\Scramble\Infer\Services\FileParser;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Exception;
use PhpParser\Node;
use PhpParser\NodeFinder;

class Extension extends OperationExtension
{
    public static array $hooks = [];

    const NOT_SUPPORTED_KEY = '--not_supported--';

    /**
     * @return Feature[]
     */
    public function features(): array
    {
        return [
            new Feature(
                Feature::AllowedIncludesMethod,
                config('query-builder.parameters.include'),
                ['posts', 'posts.comments', 'books']
            ),
            new Feature(
                Feature::AllowedFiltersMethod,
                config('query-builder.parameters.filter'),
                ['[name]=john', '[email]=gmail']
            ),
            new Feature(
                Feature::AllowedSortsMethod,
                config('query-builder.parameters.sort'),
                ['title', '-title', 'title,-id']
            ),
            new Feature(
                Feature::AllowedFieldsMethod,
                config('query-builder.parameters.fields'),
                ['id', 'title', 'posts.id']
            ),
        ];
    }

    public function getStructureComments(RouteInfo $routeInfo){
        $items = $routeInfo->phpDoc()->children;
        // dd($items);
            $queryParamValues = array_map(function($item) {
                if ($item instanceof \PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode && $item->name === '@queryParam') {
                    return $item->value->value;
                }
            }, array_filter($items, function($item) {
                return $item instanceof \PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode && $item->name === '@queryParam';
            }));
            // dd($queryParamValues);
            $structuredParams = array_map(function($param) {
                // Normalize the input to handle line breaks and multiple spaces
                $param = preg_replace('/\s+/', ' ', $param);
            
                // Initialize an array to hold the structured parameter data
                $structuredParam = [
                    'type' => null,
                    'name' => null,
                    'description' => null,
                    'examples' => [], // Initialize as an empty array for multiple examples
                    'enum' => [] // Initialize as an empty array for enum values
                ];
            
                // Extract type and name
                if (preg_match('/^(\w+)\s+([\w\[\]]+)/', $param, $matches)) {
                    $structuredParam['type'] = $matches[1];
                    $structuredParam['name'] = $matches[2];
                }
            
                // Extract and accumulate examples
                if (preg_match_all('/@example\s+([^\s@]+)/', $param, $exampleMatches)) {
                    $structuredParam['examples'] = []; // Ensure the examples array is initialized
                    foreach ($exampleMatches[1] as $example) {
                        // Check if the example starts with a quote
                        if (substr($example, 0, 1) === '"' || substr($example, 0, 1) === "'") {
                            // Remove the surrounding quotes and add the example as a single item
                            $structuredParam['examples'][] = trim($example, "\"'");
                        } else {
                            // Split by commas and add each trimmed item as an example
                            foreach (explode(',', $example) as $splitExample) {
                                $structuredParam['examples'][] = trim($splitExample);
                            }
                        }
                    }
                }
            
                // Extract enum values
                if (preg_match('/@enum\s+([^\n]+)/', $param, $enumMatches)) {
                    // Split the matched enum values string by commas and trim each value
                    $enumValues = array_map('trim', explode(',', $enumMatches[1]));
                    $structuredParam['enum'] = $enumValues;
                }
            
                // Extract description by removing type, name, examples, and enum from the original string
                $description = preg_replace('/^(\w+)\s+([\w\[\]]+)|@example\s+[^\s@]+|@enum\s+[^\s@]+/', '', $param);
                $description = preg_replace('/\s*-\s*/', '', $description);
                $description = trim($description);
                if (!empty($description)) {
                    $structuredParam['description'] = $description;
                }
            
                return $structuredParam;
            }, $queryParamValues);
            return $structuredParams;
    }
    public function handle(Operation $operation, RouteInfo $routeInfo)
    {
        foreach ($this->features() as $feature) {

            /** @var Node\Expr\MethodCall $methodCall */
            $methodCall = (new NodeFinder())->findFirst(
                $routeInfo->methodNode(),
                fn (Node $node) =>
                    // todo: check if the methodName is called on QueryBuilder
                    $node instanceof Node\Expr\MethodCall &&
                        $node->name instanceof Node\Identifier &&
                        $node->name->name === $feature->getMethodName()
            );

            if (! $methodCall) {
                continue;
            }
            $structuredParams = $this->getStructureComments($routeInfo);
            // dd($structuredParams);
            if($feature->getMethodName() == Feature::AllowedFiltersMethod || $feature->getMethodName() == Feature::AllowedFieldsMethod){            $values = $this->inferValues($methodCall, $routeInfo);
                foreach ($values as $value) {
                    $key = $feature->getQueryParameterKey()."[$value]";
                    $parameter = new Parameter($key, 'query');

                    // Step 1: Check the suffix of each key
                    $suffix = substr($value, -2);

                    $dynamicValue = "";
                    $dynamicType = new StringType();
                    // Step 2: Determine the value based on the suffix
                    switch ($suffix) {
                        case 'at':
                            $dynamicValue = date('Y-m-d'); // Current date
                            $dynamicType->format('date');
                            break;
                        case 'id':
                            $dynamicType = new IntegerType();
                            $dynamicValue = rand(1, 1000); // Random integer
                            break;
                        case 'ed':
                        case 'ng':
                            $dynamicType = new BooleanType();
                            $dynamicValue = (bool)rand(0, 1); // Random boolean
                            break;
                    }
                    $dynamicType->example($dynamicValue);
        
                    // TODO: if the parameter is already defined in the structuredParams, update the description and example
                    foreach($structuredParams as $param){
                        if($param['name'] == $key){
                            switch($param['type']){
                                case 'string':
                                    $dynamicType = new StringType();
                                    if($param['enum']){
                                        $dynamicType->enum($param['enum']);
                                    }
                                    break;
                                case 'integer':
                                    $dynamicType = new IntegerType();
                                    break;
                                case 'boolean':
                                    $dynamicType = new BooleanType();
                                    break;
                            }
                            if($param['description']){
                                $dynamicType->setDescription($param['description']);
                            }
                            // $parameter->description($param['description'] ? $param['description'] : '');
                            if($param['examples']){
                                $dynamicType->examples($param['examples']);
                                $parameter->example($param['examples'][0]);
                            }
                            // $dynamicValue =  $param['examples'] ? $param['examples'] : $dynamicValue;
                        }
                    }
                    // $feature->setValues([$dynamicValue]);
        
                    // if($dynamicValue){
                    //     if(is_array($dynamicValue) && count($dynamicValue) > 1){
                    //         $parameter->example($dynamicValue[0]);
                    //         $dynamicType->examples($dynamicValue);
                    //     }else{
                    //         $parameter->example($dynamicValue);
                    //         $dynamicType->example($dynamicValue);
                    //     }
                    // }
                    $parameter->setSchema(
                        Schema::fromType($dynamicType)
                    );
        
                    $halt = $this->runHooks($operation, $parameter, $feature);
        
                    if (! $halt) {
                        $operation->addParameters([$parameter]);
                    }
                }
            }else{
                $parameter = new Parameter($feature->getQueryParameterKey(), 'query');
                $stringType = new StringType();
                $values = $this->inferValues($methodCall, $routeInfo);
                shuffle($values);
                $examples = [];
                if($feature->getMethodName() == Feature::AllowedIncludesMethod){
                    $examples = $values;
                    $stringType->setDescription("Comma separated list of relationships to include.");
                    // $examples[] = $values[0] ? $values[0] : '';
                    // $examples[] = implode(',', $values);
                }else{
                    $stringType->setDescription("Comma separated list of values. Prefix with '-' to exclude.");
                    $examples = array_merge(...array_map(function($value) { return [$value, "-$value"]; }, $values));
                    // $examples[] = $values[0] ? $values[0] : '';
                    // $examples[] = '-'.$values[count($values)-1];
                }
                $stringType->examples($examples);
                $parameter->setSchema(
                    Schema::fromType($stringType)
                );
                $feature->setValues($examples);
                $parameter->example($examples[0]);
                $halt = $this->runHooks($operation, $parameter, $feature);
    
                if (! $halt) {
                    $operation->addParameters([$parameter]);
                }
            }
            /*if($feature->getMethodName() == Feature::AllowedFiltersMethod || $feature->getMethodName() == Feature::AllowedFieldsMethod){
                $values = $this->inferValues($methodCall, $routeInfo);
                $objectParam = new ObjectType();
                foreach ($values as $value) {
                    // Step 1: Check the suffix of each key
                    $suffix = substr($value, -2);

                    $dynamicValue = "";
                    $dynamicType = new StringType();
                    // Step 2: Determine the value based on the suffix
                    switch ($suffix) {
                        case 'at':
                            $dynamicValue = date('Y-m-d H:i:s'); // Current date and time
                            break;
                        case 'id':
                            $dynamicValue = rand(1, 1000); // Random integer
                            $dynamicType = new IntegerType();
                            break;
                        case 'ed':
                        case 'ng':
                            $dynamicValue = (bool)rand(0, 1); // Random boolean
                            $dynamicType = new BooleanType();
                            break;
                        // Add more cases as needed
                        default:
                            $dynamicValue = null;
                            break;
                    }
                    $dynamicType->example($dynamicValue);
                    $objectParam->addProperty($value, $dynamicType);
                    // $feature->setValues([$dynamicValue]);
                    
                }
                $parameter = new Parameter($feature->getQueryParameterKey(), 'query');
                $parameter->setSchema(
                    Schema::fromType($objectParam)
                );
                $halt = $this->runHooks($operation, $parameter, $feature);
    
                if (! $halt) {
                    $operation->addParameters([$parameter]);
                }
             }else{
                $parameter = new Parameter($feature->getQueryParameterKey(), 'query');
                $arrayType = new ArrayType();
                $values = $this->inferValues($methodCall, $routeInfo);
                if($feature->getMethodName() == Feature::AllowedIncludesMethod){
                    $enums = $values;
                }else{
                    $enums = [];
                    foreach ($values as $value) {
                        // Add the value as is
                        $enums[] = $value;
                        // Add the value with a "-" prefix
                        $enums[] = "-$value";
                    }
                }
                // $arrayType->setAdditionalItems(Schema::fromType((new StringType())->enum($enums)));
                $arrayType->enum($enums);
                // $arrayType->setMin(0);
                $parameter->setSchema(
                    Schema::fromType($arrayType)
                );
                $feature->setValues($values);
                $halt = $this->runHooks($operation, $parameter, $feature);
                if (! $halt) {
                    $operation->addParameters([$parameter]);
                }
             }*/
        }
    }

    public static function hook(\Closure $cb)
    {
        self::$hooks[] = $cb;
    }

    public function runHooks(Operation $operation, Parameter $parameter, Feature $feature): mixed
    {
        foreach (self::$hooks as $hook) {
            $halt = $hook($operation, $parameter, $feature);
            if ($halt) {
                return $halt;
            }
        }

        return false;
    }

    public function inferValues(Node\Expr\MethodCall $methodCall, RouteInfo $routeInfo): array
    {
        // ->allowedIncludes()
        if (count($methodCall->args) === 0) {
            return [];
        }

        // ->allowedIncludes(['posts', 'posts.author'])
        if ($methodCall->args[0]->value instanceof Node\Expr\Array_) {
            $values = [];
            foreach($methodCall->args[0]->value->items as $item){
                if($item->value instanceof Node\Scalar\String_){
                    $values[] = $item->value->value;
                }elseif($item->value instanceof Node\Expr\StaticCall && $item->value->class->name == "Spatie\QueryBuilder\AllowedFilter"){
                    switch($item->value->name->name){
                        case "scope":
                            $values[] = $item->value->args[0]->value->value;
                            break;
                        case "autoDetect":
                            $values[] = $item->value->args[1]->value->value;
                            break;
                        case "callback":
                        case "partial":
                        case "custom":
                        case "exact":
                        case "beginsWithStrict":
                        case "endsWithStrict":
                            $values[] = $item->value->args[0]->value->value;
                            break;
                    }
                    // $values[] = $this->inferValueFromStaticCall($item->value);
                }
            }
            return $values;
            // return array_map(fn (Node\Expr\ArrayItem $item) => $item->value->value, $methodCall->args[0]->value->items);
        }

        // ->allowedIncludes('posts', 'posts.author')
        if ($methodCall->args[0]->value instanceof Node\Scalar\String_) {
            return array_map(fn(Node\Arg $arg) => $arg->value->value, $methodCall->args);
        }

        // ->allowedIncludes($this->includes)
        if($methodCall->args[0]->value instanceof Node\Expr\PropertyFetch) {
            return $this->inferValuesFromPropertyFetch($methodCall->args[0]->value, $routeInfo);
        }

        // ->allowedIncludes($this->includes())
        if($methodCall->args[0]->value instanceof Node\Expr\MethodCall) {
            return $this->inferValuesFromMethodCall($methodCall->args[0]->value, $routeInfo);
        }

        return [];
    }

    public function inferValuesFromMethodCall(Node\Expr\MethodCall $node, RouteInfo $routeInfo) {
        if($node->var->name !== "this") {
            return [];
        }

        $statements = FileParser::getInstance()
            ->parseContent($this->getControllerClassContent($routeInfo))
            ->getStatements();

        /** @var Node\Stmt\ClassMethod $node */
        $node = (new NodeFinder())
            ->findFirst(
                $statements,
                fn (Node $visitedNode) =>
                    $visitedNode instanceof Node\Stmt\ClassMethod && $visitedNode->name->name === $node->name->name
            );

        /** @var Node\Stmt\Return_|null $return */
        $return = (new NodeFinder())
            ->findFirst($node->stmts, fn(Node $node) => $node instanceof Node\Stmt\Return_);

        if(!$return) {
            return [];
        }

        if(!$return->expr instanceof Node\Expr\Array_) {
            return [];
        }

        return array_map(
            function(Node\ArrayItem $item){
                if($item->value instanceof Node\Scalar\String_) {
                    return $item->value->value;
                }
                // AllowedFilter::callback(...), AllowedSort::callback
                if($item->value instanceof Node\Expr\StaticCall)  {
                    return $this->inferValueFromStaticCall($item->value);
                }

                return self::NOT_SUPPORTED_KEY;
            },
            $return->expr->items
        );
    }


    public function getControllerClassContent(RouteInfo $routeInfo) {
        [$class] = explode('@', $routeInfo->route->getAction('uses'));
        $reflection = new \ReflectionClass($class);
        return file_get_contents($reflection->getFileName());
    }

    public function inferValuesFromPropertyFetch(Node\Expr\PropertyFetch $node, RouteInfo $routeInfo) {

        if($node->var->name !== "this") {
           return [];
        }

        $statements = FileParser::getInstance()
            ->parseContent($this->getControllerClassContent($routeInfo))
            ->getStatements();

        /** @var Node\Stmt\Property $node */
        $node = (new NodeFinder())
            ->findFirst(
                $statements,
                fn (Node $visitedNode) =>
                    $visitedNode instanceof Node\Stmt\Property && $visitedNode->props[0]->name->name === $node->name->name
            );

        if(!$node->props[0]->default instanceof Node\Expr\Array_) {
            return [];
        }

        return array_map(
            fn(Node\ArrayItem $item) => $item->value->value,
            $node->props[0]->default->items
        );
    }

    public function inferValueFromStaticCall(Node\Expr\StaticCall $node) {
        switch ($node->class->name) {
            case "AllowedFilter":
                return $this->inferValueFromAllowedFilter($node);
            case "AllowedSort":
                return $this->inferValueFromAllowedSort($node);
            default:
                return self::NOT_SUPPORTED_KEY;
        }
    }

    public function inferValueFromAllowedFilter(Node\Expr\StaticCall $node) {
        switch ($node->name->name){
            case "autoDetect":
                if($node->args[1]->value instanceof Node\Scalar\String_) {
                    return $node->args[1]->value->value;
                }
                return self::NOT_SUPPORTED_KEY;
            case "callback":
            case "partial":
            case "custom":
            case "exact":
            case "beginsWithStrict":
            case "endsWithStrict":
                if($node->args[0]->value instanceof Node\Scalar\String_) {
                    return $node->args[0]->value->value;
                }
            default:
                return self::NOT_SUPPORTED_KEY;
        }
    }

    public function inferValueFromAllowedSort(Node\Expr\StaticCall $node) {
        switch ($node->name->name){
            case "callback":
            case "field":
                if($node->args[0]->value instanceof Node\Scalar\String_) {
                    return $node->args[0]->value->value;
                }
            default:
                return self::NOT_SUPPORTED_KEY;
        }
    }

    public function inferTypeAndValueFromKey(string $key){
        // Step 1: Check the suffix of each key
        $suffix = substr($key, -2);
        $dynamicType = new StringType();
        // Step 2: Determine the value based on the suffix
        switch ($suffix) {
            case 'at':
                $dynamicValue = date('Y-m-d H:i:s'); // Current date and time
                break;
            case 'id':
                $dynamicValue = rand(1, 1000); // Random integer
                $dynamicType = new IntegerType();
                break;
            case 'ed':
            case 'ng':
                $dynamicValue = (bool)rand(0, 1); // Random boolean
                $dynamicType = new BooleanType();
                break;
            // Add more cases as needed
            default:
                $dynamicValue = null;
                break;
        }
        return [
            'type' => $dynamicType,
            'value' => $dynamicValue
        ];
    }
}
