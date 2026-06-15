package types

// HttpClientCommand selects a sub-operation of the HTTP-client feature, carried in
// the payload envelope (the `cm` field) under MethodHttpClient — mirrors
// MongodbCommand under MethodMongodb.
type HttpClientCommand int

const (
	HttpClientRequest     HttpClientCommand = 1
	HttpClientUploadChunk HttpClientCommand = 2
	HttpClientUploadEnd   HttpClientCommand = 3
)
