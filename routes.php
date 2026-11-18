<?php

use Illuminate\Support\Facades\Route;

Route::get('manifest', 'BridgeController@manifest');
Route::get('rows', 'BridgeController@rows');
Route::match(['GET', 'HEAD'], 'textures/{hash}', 'BridgeController@texture')
    ->where('hash', '[0-9a-f]{64}');
