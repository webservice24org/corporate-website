<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $document['title'] }}</title>
    <style>
        body { margin: 24px; color: #172033; background: #fff; font: 12px/1.5 Arial, sans-serif; }
        h1 { font-size: 22px; margin-bottom: 8px; }
        h2 { font-size: 16px; margin-top: 28px; }
        .status { border: 2px solid #334155; padding: 12px; font-weight: bold; }
        .scope { color: #475569; }
        table { border-collapse: collapse; width: 100%; margin: 12px 0; }
        th, td { border: 1px solid #cbd5e1; text-align: left; vertical-align: top; padding: 7px; overflow-wrap: anywhere; white-space: pre-wrap; }
        th { background: #f1f5f9; }
        a { color: #172033; }
        .scroll { overflow-x: auto; }
        @page { size: landscape; margin: 12mm; }
        @media print { body { margin: 0; } .screen-only { display: none; } thead { display: table-header-group; } tr { break-inside: avoid; } .scroll { overflow: visible; } }
    </style>
</head>
<body>
    <p class="screen-only">Use your browser's Print command (Ctrl+P). This document contains all rows for the selected report basis.</p>
    <h1>{{ $document['title'] }}</h1>
    <p class="status">{{ $document['status'] }}</p>
    <p class="scope">{{ $document['scope'] }}</p>
    <table aria-label="Report basis and provenance">
        <tbody>@foreach ($document['meta'] as $pair)<tr><th scope="row">{{ $pair[0] }}</th><td>{{ $pair[1] }}</td></tr>@endforeach</tbody>
    </table>
    @foreach ($document['tables'] as $table)
        <h2>{{ $table['title'] }}</h2>
        <div class="scroll"><table>
            <caption>{{ $table['title'] }} · {{ count($table['rows']) }} rows</caption>
            <thead><tr>@foreach ($table['headers'] as $header)<th scope="col">{{ $header }}</th>@endforeach</tr></thead>
            <tbody>
                @forelse ($table['rows'] as $row)
                    <tr>@foreach ($row as $cell)<td>@if ($cell['href'] !== null)<a href="{{ $cell['href'] }}">{{ $cell['value'] }}</a>@else{{ $cell['value'] }}@endif</td>@endforeach</tr>
                @empty
                    <tr><td colspan="{{ count($table['headers']) }}">No rows for this section.</td></tr>
                @endforelse
            </tbody>
        </table></div>
    @endforeach
</body>
</html>
