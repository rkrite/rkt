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
        $vUploadedFileName = $request->file('datafile')->getClientOriginalName();
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
                $vNewData .= "     <name>VBox Data Conversion - " . $vUploadedFileName . "</name>\n";
                $vNewData .= "     <trkseg>\n";

                $vRowNum = 0;
                foreach ($vDataArray as $vDataString) {
                    if (!empty($vDataString)){
                        $vRowNum++;
                        $vDataRow = explode(" ", $vDataString);

                        // lat
                        $vLat = $vDataRow[2];
                        if ((int)$vLat != 0) {
                            $vLatSign = substr($vLat, 0,1);
                            $vLat = substr($vLat, 1) / 60;
                            $vLat_Parts = explode(".", $vLat);
                            $vLat_deg = $vLat_Parts[0];
                            $vLat_min = ("0." . ($vLat_Parts[1]??0)) * 60;
                            $vLat_min_Parts = explode(".", $vLat_min);
                            $vLat_min = $vLat_min_Parts[0];
                            $vLat_sec = ("." . ($vLat_min_Parts[1]??0)) * 60;

                            $vLat = $vLatSign . $vLat;

                            // lon
                            $vLon = $vDataRow[3];
                            $vLonSign = substr($vLon, 0,1);
                            $vLon = substr($vLon, 1) / 60;
                            $vLon_Parts = explode(".", $vLon);
                            $vLon_deg = $vLon_Parts[0];
                            $vLon_min = ("0." . ($vLon_Parts[1]??0)) * 60;
                            $vLon_min_Parts = explode(".", $vLon_min);
                            $vLon_min = $vLon_min_Parts[0];
                            $vLon_sec = ("." . ($vLon_min_Parts[1]??00)) * 60;

                            // $vLon = $vLonSign . $vLon;

                            $vNewDataRow = "<trkpt lat=\"" . $vLat . "\" lon=\"" . $vLon . "\">\n";

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
                        } // latitude was captured
                    } // no empty row
                }

                $vNewData .= "     </trkseg>\n";
                $vNewData .= " </trk>\n";
                $vNewData .= "</gpx>\n";

                break;
        }
        $vNewFileName = 'converted-' . strtolower($vUploadedFileName) . '-' . strtolower(Str::random(5)) . '.gpx';
        return response()->streamDownload(function () use ($vNewData) {
            echo $vNewData;
        }, $vNewFileName);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

}
