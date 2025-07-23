<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>VBox Data Formatter</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        <!-- Styles / Scripts -->
{{--         @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
 --}}
        <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <style type="text/tailwindcss">
      @theme {
        --color-greyish: #888;
        --color-lightgrey: #ccc;
        --color-blueish: #257;
        --color-redish: #725;
      }
    </style>


    </head>
<body class="m-5 text-blueish">
    <h1 class="text-2xl font-bold">VBox Data Converter</h1>

    <form method="POST" enctype="multipart/form-data" action="{{ route('data.convert')  }}">
        @csrf
        <div class="border-redish mt-2">
            <p class="mt-3">Convert raw VBox ".vbo" files into ".gpx" data files.</p>

            <div class="mt-10">

                <label for="datafile" class="text-sm/6">Choose a VBox data file</label>
                <div class="mt-2 mb-5">
                    <div class="rounded-md pl-3 outline-1 outline-redish focus-within:outline-2">
                        <input id="datafile" type="file" name="datafile" placeholder="datafile" class="py-1.5 pl-1 text-base text-redish placeholder:text-redish focus:outline-none sm:text-sm/6" />
                    </div>
                </div>

                <label for="datatype" class="text-sm/6">Choose a data format</label>
                <div class="mt-2 mb-5">
                    <select id="datatype" name="datatype" autocomplete="datatype-name" class="w-full appearance-none rounded-md py-1.5 pl-3 text-redish outline-1 outline-redish focus:outline-2 sm:text-sm/6">
                        <option value="">select...</option>
                        <option value="gpx" selected>GPX</option>
                        <option value="kml">KML</option>
                    </select>
                </div>
            </div>
            <div class="mt-6 flex items-center gap-x-4">
                <button type="reset" alt="Clear the form" class="text-sm/6 pl-2 pr-2 pt-1 pb-1 rounded-md outline-1 outline-redish font-semibold text-greyish focus:outline-2 hover:bg-lightgrey">Cancel</button>
                <button type="submit" class="text-sm/6 pl-2 pr-2 pt-1 pb-1 rounded-md outline-1 outline-redish font-semibold text-blueish focus:outline-2 hover:bg-lightgrey">Submit</button>
            </div>

            @if (isset($newdata))
                <div>
                    <pre>{{ $newdata }}</pre>
                </div>
            @endif


        </div>
    </form>
</body>
</html>
