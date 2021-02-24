const mix = require('laravel-mix')

mix.setPublicPath('dist').vue().js('resources/js/field.js', 'js')
