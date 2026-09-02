package types

// HttpClientCommand selects a sub-operation of the HTTP-client feature, carried in
// the payload envelope (the `cm` field) under MethodHttpClient — mirrors
// MongodbCommand under MethodMongodb.
type HttpClientCommand string

const (
	HttpClientRequest     HttpClientCommand = "req"
	HttpClientUploadChunk HttpClientCommand = "upc"
	HttpClientUploadEnd   HttpClientCommand = "upe"
)
