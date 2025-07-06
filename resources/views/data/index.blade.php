<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>VBox Data Formatter</title>
        <!-- Styles / Scripts -->
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

    </head>
<body>
    <h1>{{ route('data.convert') }}</h1>
    <form method="POST" enctype="multipart/form-data" action="{{ route('data.convert')  }}">
    @csrf
        <div>
            <label for="datafile">Choose a VBox data file:</label>
            <input type="file" id="datafile" name="datafile" />
        </div>

        <div>
            <label for="datatype">Choose a data format:</label>
            <select name="datatype" id="datatype">
                <option value="">select...</option>
                <option value="gpx">GPX</option>
                <option value="kml">KML</option>
            </select>
        </div>

        <div>
            <button type="submit">Submit</button>
        </div>

        @if (isset($newdata))
            <div>
                <pre>{{ $newdata }}</pre>
            </div>
        @endif

    </form>
</body>
</html>
