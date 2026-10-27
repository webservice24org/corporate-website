<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $document['title'] }}</title>
    <style>
        body { font: 13px/1.5 Arial, sans-serif; color: #111; margin: 24px; }
        h1 { font-size: 24px; } h2 { font-size: 17px; margin-top: 24px; }
        table { border-collapse: collapse; width: 100%; margin-top: 12px; }
        th, td { border: 1px solid #aaa; padding: 6px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        th { background: #eee; } .scroll { overflow-x: auto; } .review { border: 2px solid #111; padding: 12px; font-weight: bold; }
        button { padding: 8px 16px; } caption { text-align: left; font-weight: bold; margin-bottom: 8px; }
        @media print { body { margin: 0; font-size: 10px; } .no-print { display: none; } thead { display: table-header-group; } tr { break-inside: avoid; } .scroll { overflow: visible; } }
        @page { size: A4 landscape; margin: 12mm; }
    </style>
</head>
<body>
    <button type="button" class="no-print" onclick="window.print()">Print report</button>
    <h1>{{ $document['title'] }}</h1>
    @if ($document['review'])
        <p class="review">REVIEW REQUIRED — figures are provisional. Reconciliation has not been proven. Read the coverage and exceptions below.</p>
    @endif
    <table aria-label="Report scope"><tbody>@foreach ($document['meta'] as $row)<tr><th scope="row">{{ $row[0] }}</th><td>{{ $row[1] }}</td></tr>@endforeach</tbody></table>
    <div class="scroll"><table><caption>All filtered rows</caption><thead><tr>@foreach ($document['headers'] as $header)<th scope="col">{{ $header }}</th>@endforeach</tr></thead><tbody>
        @forelse ($document['rows'] as $row)<tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($document['headers']) }}">No rows match the selected filters.</td></tr>@endforelse
    </tbody></table></div>
    <h2>Full filtered totals</h2>
    <table><tbody>@foreach ($document['totals'] as $row)<tr><th scope="row">{{ $row[0] }}</th><td>{{ $row[1] }}</td></tr>@endforeach</tbody></table>
    <h2>Exceptions</h2>
    @if (count($document['exceptions']) === 0)<p>No exceptions detected within the declared scope.</p>@else
        <table><thead><tr>@foreach (['Code', 'Supplier ID', 'Source kind', 'Source ID', 'Journal line ID', 'Account ID'] as $header)<th scope="col">{{ $header }}</th>@endforeach</tr></thead><tbody>@foreach ($document['exceptions'] as $row)<tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody></table>
    @endif
    <p>All values are exact decimal strings in the stated currency. Allocations do not create another cash movement. Current native due dates do not establish historical aging.</p>
</body>
</html>
