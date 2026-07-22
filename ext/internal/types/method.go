package types

type Method string

const (
	MethodSleep       Method = "sl"
	MethodMongodb     Method = "mng"
	MethodHttpServe   Method = "hs"
	MethodHttpRespond Method = "hr"
	MethodHttpClient  Method = "hc"
	MethodMysql       Method = "my"
	MethodPgsql       Method = "pg"
	MethodSocketServe   Method = "ss"
	MethodSocketRespond Method = "sr"
	MethodSocketClient  Method = "sc"
	MethodWsServe    Method = "wss"
	MethodWsRespond  Method = "wsr"
	MethodWsClient   Method = "wsc"
)
