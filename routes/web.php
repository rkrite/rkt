<?php

use Illuminate\Support\Facades\Route;

// Route::get('/', function () {
//     return view('welcome');
// });

Route::get('/pi', function () {
    phpinfo();
});


Route::get('/', 'App\Http\Controllers\DataController@index')->name('data.index');
Route::post('/convert', 'App\Http\Controllers\DataController@convert')->name('data.convert');
Route::get('/clear', 'App\Http\Controllers\DataController@clear')->name('data.clear');

