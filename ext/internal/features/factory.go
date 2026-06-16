package features

import (
	"errors"
	"fmt"
	"sconcur/internal/contracts"
	"sconcur/internal/features/httpclient"
	"sconcur/internal/features/httpserver"
	"sconcur/internal/features/mongodb/connection"
	"sconcur/internal/features/mongodb/features/collection"
	"sconcur/internal/features/sleeper"
	"sconcur/internal/types"
)

func DetectMessageHandler(method types.Method) (contracts.FeatureContract, error) {
	switch method {
	case types.MethodSleep:
		return sleeper_feature.Get(), nil
	case types.MethodMongodb:
		return collection_feature.GetCollectionFeature(), nil
	case types.MethodHttpServe, types.MethodHttpRespond:
		return httpserver_feature.Get(), nil
	case types.MethodHttpClient:
		return httpclient_feature.Get(), nil
	default:
		return nil, errors.New("unknown method: " + fmt.Sprint(method))
	}
}

// Shutdown releases resources held by features (MongoDB clients and their
// connection pools, the HTTP-client idle connections).
func Shutdown() {
	connection.GetClients().DisconnectAll()
	httpclient_feature.CloseIdleConnections()
}
