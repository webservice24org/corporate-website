<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ $title }}</title>
    <style>
        body { font-family: sans-serif; color: #172033; margin: 18px; font-size: 10px; }
        h1 { font-size: 22px; } p { overflow-wrap: anywhere; }
        table { border-collapse: collapse; width: 100%; table-layout: fixed; }
        th, td { padding: 5px; border: 1px solid #ccd2dc; text-align: left; overflow-wrap: anywhere; }
        thead { display: table-header-group; } tr { break-inside: avoid; }
        @page { size: landscape; margin: 10mm; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    @foreach ($metadata as $row)<p>{{ implode(' · ', $row) }}</p>@endforeach
    <p>All documents and coverage issues. Unknown is not zero. Current supplier terms do not establish historical terms. Use your browser’s Print command.</p>
    <table><caption>{{ $title }} — complete applied scope</caption><thead><tr>@foreach ($headers as $header)<th scope="col">{{ $header }}</th>@endforeach</tr></thead><tbody>@foreach ($rows as $row)<tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody></table>
</body>
</html>
