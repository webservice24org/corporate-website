<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ $title }}</title>
    <style>
        body { font-family: sans-serif; color: #172033; margin: 24px; font-size: 12px; }
        h1 { font-size: 24px; } p { overflow-wrap: anywhere; }
        table { border-collapse: collapse; width: 100%; }
        th, td { padding: 7px; border: 1px solid #ccd2dc; text-align: left; overflow-wrap: anywhere; }
        thead { display: table-header-group; } tr { break-inside: avoid; }
        @page { size: landscape; margin: 12mm; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    @foreach ($metadata as $row)
        <p>{{ implode(' · ', $row) }}</p>
    @endforeach
    <p>All filtered rows. Use your browser’s Print command. Journal evidence checks do not certify native-source completeness.</p>
    <table>
        <caption>{{ $title }} — complete filtered scope</caption>
        <thead><tr>@foreach ($headers as $header)<th scope="col">{{ $header }}</th>@endforeach</tr></thead>
        <tbody>@foreach ($rows as $row)<tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody>
    </table>
</body>
</html>
