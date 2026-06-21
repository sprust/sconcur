package types

type Method int

const (
	MethodSleep       Method = 1
	MethodMongodb     Method = 2
	MethodHttpServe   Method = 3
	MethodHttpRespond Method = 4
	MethodHttpClient  Method = 5
	MethodMysql       Method = 6
	MethodPgsql       Method = 7
	MethodSocketServe   Method = 8
	MethodSocketRespond Method = 9
	MethodSocketClient  Method = 10
)
