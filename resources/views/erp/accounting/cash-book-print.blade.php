<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $document['title'] }}</title>
    <style>
        body { font: 13px/1.5 Arial, sans-serif; margin: 24px; color: #111; }
        h1 { font-size: 24px; } h2 { margin-top: 24px; font-size: 17px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #aaa; padding: 6px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        th { background: #eee; } caption { text-align: left; font-weight: bold; margin-bottom: 8px; }
        .review { border: 2px solid #111; padding: 12px; font-weight: bold; }
        .scroll { overflow-x: auto; } button { padding: 8px 16px; }
        @media print { body { margin: 0; font-size: 9px; } .no-print { display: none; } thead { display: table-header-group; } tr { break-inside: avoid; } .scroll { overflow: visible; } }
        @page { size: A4 landscape; margin: 12mm; }
    </style>
</head>
<body>
    <button type="button" onclick="window.print()" class="no-print">Print book</button>
    <h1>{{ $document['title'] }}</h1>
    @if ($document['review'])
        <p class="review">COVERAGE REVIEW REQUIRED — figures are provisional. Read the source scope, excluded-line count and exceptions below.</p>
    @endif
    <table aria-label="Book scope"><tbody>
        @foreach ($document['meta'] as $row)
            <tr><th scope="row">{{ $row[0] }}</th><td>{{ $row[1] }}</td></tr>
        @endforeach
    </tbody></table>
    <h2>Full filtered totals</h2>
    <table><tbody>
        @foreach ($document['totals'] as $row)
            <tr><th scope="row">{{ $row[0] }}</th><td>{{ $row[1] }}</td></tr>
        @endforeach
    </tbody></table>
    <div class="scroll"><table>
        <caption>All filtered rows</caption>
        <thead><tr>@foreach ($document['headers'] as $header)<th scope="col">{{ $header }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse ($document['rows'] as $row)
                <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($document['headers']) }}">No movements in this window. Opening and full-period totals still apply.</td></tr>
            @endforelse
        </tbody>
    </table></div>
    <h2>Coverage exceptions</h2>
    @if (count($document['exceptions']) === 0)
        <p>No exceptions detected within the declared supported scope.</p>
    @else
        <table><thead><tr><th scope="col">Code</th><th scope="col">Journal ID</th><th scope="col">Journal line ID</th></tr></thead><tbody>
            @foreach ($document['exceptions'] as $row)<tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach
        </tbody></table>
    @endif
    <p>Posted GL and source-verified figures remain distinct. Opening + debits − credits = closing. A zero closing difference alone does not establish complete coverage. These figures do not establish bank statement agreement or available funds.</p>
</body>
</html>
