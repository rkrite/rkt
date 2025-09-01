<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

// Added
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Illuminate\Support\Str;

class DataController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('data.index');
    } // index

    /**
     * Display a listing of the resource.
     */
    public function clear()
    {
        return $this->index();
    } // clear

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    } // store

    /**
     * Convert and display the uploaded data file
     */
    public function convert(Request $request)
    {
        $request->validate([
            'datasourcetype' => 'required',
            'datafile' => 'required',
            'datatype' => 'required',
        ]);

        $vDataSourcetypeType = $request->input('datasourcetype');
        switch ($vDataSourcetypeType) {
            case 'djilog':
                return (GConvertDJILogDataFile($request));
                break;
            case 'vbox':
            default:
                return (GConvertVBoxDataFile($request));
                break;
        }

    } // convert

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    } // show

}
