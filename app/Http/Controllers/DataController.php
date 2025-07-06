<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

// Added
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class DataController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('data.index');
    }

    /**
     * Display a listing of the resource.
     */
    public function clear()
    {
        return $this->index();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Convert and display the uploaded data file
     */
    public function convert(Request $request)
    {

        $vDataStartTag = '[data]';
        $vDataStartTagLen = strlen($vDataStartTag) + 1;

        $request->validate([
            'datafile' => 'required',
            'datatype' => 'required',
        ]);

        $vFormatType = $request->input('datatype');
        $vInitialContents = file_get_contents($request->file('datafile')->getRealPath());
        $vInitialContents = str_replace("\r\n", "\n", $vInitialContents);
        $vInitialContents = str_replace(" \n", "\n", $vInitialContents);
        $vDataStartPos = strpos($vInitialContents, $vDataStartTag) + $vDataStartTagLen;
        $vDataArray = explode("\n", substr($vInitialContents, $vDataStartPos));

        $vFileDate = substr($vInitialContents, 16, 10);
        $vFileDate = Carbon::createFromFormat('d/m/Y', $vFileDate)->format('Y-m-d');

        switch ($vFormatType) {
            case 'gpx':
            default:
                $vNewDataRow = "";
                $vNewData = "<?xml version=\"1.0\" encoding=\"utf-8\" standalone=\"yes\"?>\n";
                $vNewData .= "<gpx version=\"1.1\" creator=\"GPS Visualizer https://www.gpsvisualizer.com/\" xmlns=\"http://www.topografix.com/GPX/1/1\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xsi:schemaLocation=\"http://www.topografix.com/GPX/1/1 http://www.topografix.com/GPX/1/1/gpx.xsd http://www.garmin.com/xmlschemas/TrackPointExtension/v2 http://www.garmin.com/xmlschemas/TrackPointExtensionv2.xsd\" xmlns:gpxtpx=\"http://www.garmin.com/xmlschemas/TrackPointExtension/v2\">\n";
                $vNewData .= " <trk>\n";
                $vNewData .= "     <name>VBox Data Conversion</name>\n";
                $vNewData .= "     <trkseg>\n";

                // foreach ($vDataArray as $vDataString) {
                for ($i=0; $i < 10; $i++) {
                    $vDataRow = explode(" ", $vDataArray[$i]);
                    // lat lon
                    $vLat = $vDataRow[2];
                    $vLon = $vDataRow[3];
                    $vNewDataRow = "<trkpt lat=\"" . $vLat . "\" lon=\"" . $vDataRow[3] . "\">\n";

                    // Sat
                    $vNewDataRow .= "   <sat>" . (int)$vDataRow[0] . "</sat>" . "\n";

                    // Height
                    $vNewDataRow .= "   <ele>" . (int)$vDataRow[6] . "</ele>" . "\n";

                    // Time
                    $vRowTimeStr = $vDataRow[1];
                    $vRowTimeHH = substr($vRowTimeStr, 0,2);
                    $vRowTimeMM = substr($vRowTimeStr, 2,2);
                    $vRowTimeSS = substr($vRowTimeStr, 4,2);
                    $vRowTimeMI = substr($vRowTimeStr, 7,2);
                    $vRowTime = $vRowTimeHH . ":" . $vRowTimeMM . ":" . $vRowTimeSS . "." . $vRowTimeMI;
                    $vNewDataRow .= "   <time>" . $vFileDate . "T" .  $vRowTime . "Z</time>\n";

                    // Extensions
                    $vNewDataRow .= "   <extensions>" . "\n";
                    $vNewDataRow .= "       <gpxtpx:TrackPointExtension>" . "\n";

                    // Speed
                    $vNewDataRow .= "           <gpxtpx:speed>" . (int)$vDataRow[4] . "</gpxtpx:speed>\n";

                    // Course
                    $vNewDataRow .= "           <gpxtpx:course>" . (int)$vDataRow[5] . "</gpxtpx:course>\n";

                    $vNewDataRow .= "       </gpxtpx:TrackPointExtension>" . "\n";
                    $vNewDataRow .= "   </extensions>" . "\n";
                    $vNewDataRow .= "</trkpt>\n";
                    $vNewData .= $vNewDataRow;
                }

                $vNewData .= "     </trkseg>\n";
                $vNewData .= " </trk>\n";
                $vNewData .= "</gpx>\n";

                break;
        }

        return view('data.show')->with('newdata', $vNewData);

    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

}
