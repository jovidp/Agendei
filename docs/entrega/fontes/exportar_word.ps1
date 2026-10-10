$ErrorActionPreference = 'Stop'
$pastaEntrega = Split-Path -Parent $PSScriptRoot
$wordEntrega = $null
try {
    $wordEntrega = New-Object -ComObject Word.Application
    $wordEntrega.Visible = $false
    $wordEntrega.DisplayAlerts = 0
    foreach ($arquivoEntrega in Get-ChildItem -LiteralPath $pastaEntrega -Filter '*.docx') {
        $documentoEntrega = $wordEntrega.Documents.Open($arquivoEntrega.FullName, $false, $false)
        try {
            $null = $documentoEntrega.Fields.Update()
            foreach ($sumarioEntrega in $documentoEntrega.TablesOfContents) { $sumarioEntrega.Update() }
            $documentoEntrega.Repaginate()
            $documentoEntrega.Save()
            $pdfEntrega = [System.IO.Path]::ChangeExtension($arquivoEntrega.FullName, '.pdf')
            $documentoEntrega.ExportAsFixedFormat($pdfEntrega, 17)
            Write-Output ($arquivoEntrega.Name + ': ' + $documentoEntrega.ComputeStatistics(2) + ' paginas')
            if ($arquivoEntrega.Name.StartsWith('AGENDEI - ')) {
                $docEntrega = [System.IO.Path]::ChangeExtension($arquivoEntrega.FullName, '.doc')
                $documentoEntrega.SaveAs2($docEntrega, 0)
                Write-Output 'Arquivo .doc binario do Word exportado.'
            }
        } finally { $documentoEntrega.Close(0) }
    }
} finally {
    if ($null -ne $wordEntrega) {
        $wordEntrega.Quit()
        $null = [System.Runtime.InteropServices.Marshal]::FinalReleaseComObject($wordEntrega)
    }
}
