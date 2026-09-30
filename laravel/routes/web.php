<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Mensageria;

Route::get('/',[Mensageria::class,'welcomePage']);
Route::get("/mensageria",[Mensageria::class, 'index']);
Route::post("/checkout",[Mensageria::class,'checkout']);
