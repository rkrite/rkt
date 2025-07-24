@include('layouts.head')
<body>
    <div>
        @if (isset($newdata))
            <pre>{{ $newdata }}</pre>
        @endif
    </div>
@include('layouts.footer')
    </body>
@include('layouts.foot')
