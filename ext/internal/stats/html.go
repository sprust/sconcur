package stats

import (
	"bytes"
	"fmt"
	"html/template"
)

// htmlTemplate renders the aggregate as a compact, dependency-free admin page:
// a header line, a totals row and a per-worker table. Hung workers are
// highlighted. The workload columns (requests vs connections) are chosen once
// from the pool totals so every worker row has the same shape; a worker missing
// that section shows dashes.
var htmlTemplate = template.Must(template.New("stats").Funcs(template.FuncMap{
	"mib": func(bytesValue int64) string { return fmt.Sprintf("%.1f", float64(bytesValue)/(1024*1024)) },
	"f1":  func(value float64) string { return fmt.Sprintf("%.1f", value) },
}).Parse(htmlSource))

// renderHTML builds the HTML representation of the aggregate response.
func renderHTML(response AggregateResponse) ([]byte, error) {
	var buffer bytes.Buffer

	if err := htmlTemplate.Execute(&buffer, response); err != nil {
		return nil, err
	}

	return buffer.Bytes(), nil
}

const htmlSource = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{.Name}} — stats</title>
<style>
 body{font:13px/1.4 ui-monospace,Menlo,Consolas,monospace;margin:1.2rem;color:#222}
 h1{font-size:1.05rem;margin:0 0 .2rem}
 .meta{color:#666;margin-bottom:1rem}
 .meta .hung{color:#a00}
 table{border-collapse:collapse;margin:.3rem 0 1.3rem}
 caption{text-align:left;font-weight:bold;margin-bottom:.3rem}
 th,td{border:1px solid #ddd;padding:.25rem .55rem;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
 th{background:#f4f4f4}
 th:first-child,td:first-child{text-align:left}
 tr.hung td{background:#fde8e8;color:#a00}
</style>
</head>
<body>
<h1>{{.Name}}</h1>
<div class="meta">{{.GeneratedAt}} · workers {{.WorkersTotal}}{{if .WorkersHung}} · <span class="hung">hung {{.WorkersHung}}</span>{{end}}</div>

<table>
<caption>Totals</caption>
<tr>
<th>RSS, MiB</th><th>Go runtime, MiB</th><th>non-ext, MiB</th><th>CPU %</th><th>goroutines</th>
{{if .Totals.Requests}}<th>completed</th><th>avg ms</th><th>in-flight</th><th>1–5s</th><th>5–15s</th><th>&gt;15s</th>{{end}}
{{if .Totals.Connections}}<th>active</th><th>accepted</th>{{end}}
</tr>
<tr>
<td>{{mib .Totals.Memory.RssBytes}}</td>
<td>{{mib .Totals.Memory.GoRuntimeBytes}}</td>
<td>{{mib .Totals.Memory.NonExtensionBytes}}</td>
<td>{{f1 .Totals.CpuPercent}}</td>
<td>{{.Totals.Goroutines}}</td>
{{with .Totals.Requests}}<td>{{.Completed}}</td><td>{{f1 .AvgMs}}</td><td>{{.InFlight}}</td><td>{{.InFlight1to5s}}</td><td>{{.InFlight5to15s}}</td><td>{{.InFlightOver15s}}</td>{{end}}
{{with .Totals.Connections}}<td>{{.Active}}</td><td>{{.TotalAccepted}}</td>{{end}}
</tr>
</table>

<table>
<caption>Workers</caption>
<tr>
<th>pid</th><th>uptime s</th><th>age ms</th><th>CPU %</th><th>RSS, MiB</th><th>goroutines</th>
{{if .Totals.Requests}}<th>completed</th><th>avg ms</th><th>in-flight</th>{{end}}
{{if .Totals.Connections}}<th>active</th><th>accepted</th>{{end}}
</tr>
{{range .Workers}}
<tr{{if .Hung}} class="hung"{{end}}>
<td>{{.Pid}}{{if .Hung}} ⚠{{end}}</td>
<td>{{f1 .UptimeSeconds}}</td>
<td>{{.SnapshotAgeMs}}</td>
<td>{{f1 .CpuPercent}}</td>
<td>{{mib .Memory.RssBytes}}</td>
<td>{{.Goroutines}}</td>
{{if $.Totals.Requests}}{{with .Requests}}<td>{{.Completed}}</td><td>{{f1 .AvgMs}}</td><td>{{.InFlight}}</td>{{else}}<td>—</td><td>—</td><td>—</td>{{end}}{{end}}
{{if $.Totals.Connections}}{{with .Connections}}<td>{{.Active}}</td><td>{{.TotalAccepted}}</td>{{else}}<td>—</td><td>—</td>{{end}}{{end}}
</tr>
{{end}}
</table>
</body>
</html>`
