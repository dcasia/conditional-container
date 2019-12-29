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

    use HasConditionalContainer; // Important!!

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
                                ->if('option = 1'),
    
            /**
             * Equivalent to: if($option === 2 && $content === 'hello world')
             */
            ConditionalContainer::make([ Text::make('Field B') ])
                                ->if('option = 2 AND content = "hello world"'),
    
            /**
             * Equivalent to: if(($option !== 2 && $content > 10) || $option === 3)
             */
            ConditionalContainer::make([ Text::make('Field C')->rules('required') ])
                                ->if('(option != 2 AND content > 10) OR option = 3'),
           
            /**
             * Example with Validation and nested ConditionalContainer!
             * Equivalent to: if($option === 3 || $content === 'demo')
             */
            ConditionalContainer::make([

                                    Text::make('Field D')->rules('required') // Yeah! validation works flawlessly!!
                                
                                    ConditionalContainer::make([ Text::make('Field E') ])
                                                        ->if('field_d = Nice!')
                                
                                ])
                                ->if('option = 3 OR content = demo')
        ];
    }

}
```

The `->if()` method takes a single expression argument that follows this format:
 
```
(attribute COMPARATOR value) OPERATOR ...so on
```
 
you can build any complex logical operation by wrapping your condition in `()` examples:

```php
ConditionalContainer::make(...)->if('first_name = John');
ConditionalContainer::make(...)->if('(first_name = John AND last_name = Doe) OR (first_name = foo AND NOT last_name = bar)');
ConditionalContainer::make(...)->if('first_name = John AND last_name = Doe');
```

you can chain multiple `->if()` together to group your expressions by concern, example:

```php
ConditionalContainer::make(...)
                    //->useAndOperator()
                    ->if('age > 18 AND gender = male')
                    ->if('A contains "some word"')
                    ->if('B contains "another word"');
```

by default the operation applied on each `->if()` will be `OR`, therefore if any of the if methods evaluates to true the whole 
operation will be considered truthy, if you want to execute an `AND` operation instead append `->useAndOperator()` to the chain

### Currently supported operators:

- AND
- OR
- NOT
- XOR
- and parentheses

### Currently supported comparators:

| Comparator          | Description                                    |
|---------------------|------------------------------------------------|
| >                   | Greater than                                   |
| <                   | Less than                                      |
| <=                  | Less than or equal to                          |
| >=                  | Greater than or equal to                       |
| ==                  | Equal                                          |
| ===                 | Identical                                      |
| !=                  | Not equal                                      |
| !==                 | Not Identical                                  |
| truthy / boolean    | Validate against truthy values                 |
| contains / includes | Check if input contains certain value          |
| startsWith          | Check if input starts with certain value       |
| endsWith            | Check if input ends with certain value         |

#### Examples

* Display field only if user has selected file

```php
[
    Image::make('Image'),
    ConditionalContainer::make([ Text::make('caption')->rules('required') ])
                        ->if('image truthy true'),
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
                        ->if(function () {
                            return 'fileable = ' . App\Nova\Image::uriKey();
                        })
                        ->if(function () {
                            return 'fileable = ' . App\Nova\Video::uriKey();
                        })
]
```

* Display inline HTML only if `Reason` field is empty, show extra fields otherwise.

```php
[
    Trix::make('Reason'),
    
    ConditionalContainer::make([ Text::make('Extra Information')->rules('required') ])
                        ->if('reason truthy true'),
    
    ConditionalContainer::make([
                            Heading::make('<p class="text-danger">Please write a good reason...</p>')->asHtml()
                        ])
                        ->if('reason truthy false'),
]
```

## License

The MIT License (MIT). Please see [License File](https://raw.githubusercontent.com/dcasia/conditional-container/master/LICENSE) for more information.
