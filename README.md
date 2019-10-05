# Conditional Container

[![Latest Version on Packagist](https://img.shields.io/packagist/v/digital-creative/conditional-container)](https://packagist.org/packages/digital-creative/conditional-container)
[![Total Downloads](https://img.shields.io/packagist/dt/digital-creative/conditional-container)](https://packagist.org/packages/digital-creative/conditional-container)
[![License](https://img.shields.io/packagist/l/digital-creative/conditional-container)](https://github.com/dcasia/conditional-container/blob/master/LICENSE)

![Laravel Nova Conditional Container in action](https://raw.githubusercontent.com/dcasia/conditional-container/master/demo.gif)

Provides an easy way to conditionally show and hide fields in your Nova resources.

# Installation

You can install the package via composer:

```
composer require digital-creative/conditional-container
```

## Usage

Basic demo showing the power of this field:

```php

use DigitalCreative\ConditionalContainer\ConditionalContainer;
use DigitalCreative\ConditionalContainer\HasConditionalContainer;

class ExampleNovaResource extends Resource {

    use HasConditionalContainer; // Imporant!!

    public function fields(Request $request)
    {
        return [
    
            Select::make('Option', 'option')
                  ->options([
                      1 => 'Option 1',
                      2 => 'Option 2',
                      3 => 'Option 3',
                  ]),
    
            Text::make('Content', 'content')->rules('required'),
    
            /**
             * Only show field Text::make('Field A') if the value of option is equals 1
             */
            ConditionalContainer::make([ Text::make('Field A') ])
                                ->if('option', 1),
    
            /**
             * Equivalent to: if($option === 2 && $content === 'example')
             */
            ConditionalContainer::make([ Text::make('Field B') ])
                                ->if([ 'option', 2 ], [ 'content', '===', 'example' ]),
    
            /**
             * Equivalent to: if($option !== 2 && $content > 10)
             */
            ConditionalContainer::make([ Text::make('Field C')->rules('required') ])
                                ->if([ 'option', '!==', 2 ], [ 'content', '>', 10 ]),
           
            /**
             * Example with Validation and nested ConditionalContainer!
             * Equivalent to: if($option === 3 || $content === 'demo')
             */
            ConditionalContainer::make([

                                    Text::make('Field D')->rules('required') // Yeah! validatio works flawlessly!!
                                
                                    ConditionalContainer::make([ Text::make('Field E') ])
                                                        ->if('field_d', 'Nice!')
                                
                                ])
                                ->if('option', 3)
                                ->if('content', 'demo')
        ];
    }

}
```

The `->if()` method takes 3 arguments:
 - `$attribute` the name of the other field this ConditionalContainer will use for comparision
 - `$operator` one of the supported operator symbol.
 - `$value` the actual expected value of the attribute to consider the operation truthy 

```php
public function if(string $attribute, string $operator = '===', $value = null) {
  //...
}
```

If the third argument is omitted, the operator will default to `===`.
Example of an equivalent output: `->if('option', 1)` and `->if('option', '===', 1)`

#### Current Supported Operators

| Operator | Description                    |
|----------|--------------------------------|
| >        | Greater than                   |
| <        | Less than                      |
| <=       | Less than or equal to          |
| >=       | Greater than or equal to       |
| ==       | Equal                          |
| ===      | Identical                      |
| !=       | Not equal                      |
| !==      | Not Identical                  |
| truthy   | Validate against truthy values |

#### MorphTo

When dealing with a `morphTo` relationship you can pass either ID for comparision or the class itself example:

```php
public function fields(Request $request)
{
    return [

        MorphTo::make('commentable')->types([
            App\Nova\Video::class,
            App\Nova\Image::class,
            App\Nova\Post::class,
        ]),

        ConditionalContainer::make([ ... ])->if('commentable', '===', App\Nova\Video::class),
        // or
        ConditionalContainer::make([ ... ])->if('commentable', '===', 31)

    ];
}
```

#### Examples

* Display field only if user has selected file

```php
[
    Image::make('Image'),
    ConditionalContainer::make([ Text::make('caption')->rules('required') ])
                        ->if('image', 'truthy', true),
]
```

* Display extra fields only if selected morph relation is of type Image or Video

```php
[
    MorphTo::make('Resource Type', 'fileable')->types([
        App\Nova\Image::class,
        App\Nova\Video::class,
        App\Nova\File::class,
    ]),
    
    ConditionalContainer::make([ Image::make('thumbnail')->rules('required') ])
                        ->if('fileable', App\Nova\Image::class)
                        ->if('fileable', App\Nova\Video::class),
]
```

* Display inline HTML only if `Reason` field is empty, show extra fields otherwise.

```php
[
    Trix::make('Reason'),
    
    ConditionalContainer::make([ Text::make('Extra Information')->rules('required') ])
                        ->if('reason', 'truthy', true),
    
    ConditionalContainer::make([
                            Heading::make('<p class="text-danger">Please write a good reason...</p>')->asHtml()
                        ])
                        ->if('reason', 'truthy', false),
]
```
## Notes

While inspired by [nova-dependency-container](https://github.com/epartment/nova-dependency-container), 
ConditionalContainer takes a different approach to solve somewhat the same problem. 
However, you can expect no issues with any third-party package at all!! as the way it was implemented 
tries to be the least intrusive as possible.

However it hasn't yet been battle-tested against every use case out there but my own, so if you find any issue 
please let us know or if you know how to fix it don't hesitate to submit a PR :)

## Roadmap

- Add more operators such as `between`, `contains`, `startWith / endWith`, `length` etc..
- Add Support for depending on a field that is within another ConditionalContainer, example:

```php
[
    Text::make('A'),
  
    ConditionalContainer::make([ Text::make('B') ])->if('a', '===', 'foo') // Works!
    ConditionalContainer::make([ Text::make('C') ])->if('b', '===', 'foo') // Doesnt work!
    ConditionalContainer::make([ 
        Text::make('D'),
        ConditionalContainer::make([ Text::make('E') ])->if('d', '===', 'foo') // Works!
    ])->if('a', '===', 'foo')
    
    // currently it's not possible to depend on a field that is within a ConditionalContainer as seen on the second ConditionalContainer above
]
```

## License

The MIT License (MIT). Please see [License File](https://raw.githubusercontent.com/dcasia/conditional-container/master/LICENSE) for more information.
