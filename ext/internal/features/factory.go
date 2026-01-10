package features

import (
	"errors"
	"fmt"
	"sconcur/internal/contracts"
	"sconcur/internal/features/mongodb/features/collection"
	"sconcur/internal/features/mongodb/features/stateful"
	"sconcur/internal/features/sleep"
	"sconcur/internal/types"
)

func DetectMessageHandler(method types.Method) (contracts.FeatureContract, error) {
	switch method {
	case 1: // sleep
		return sleep_feature.Get(), nil
	case 2: // mongodb collection
		return collection_feature.GetCollectionFeature(), nil
	case 3: // mongodb stateful
		return stateful_feature.GetCollectionStatefulFeature(), nil
	default:
		return nil, errors.New("unknown method: " + fmt.Sprint(method))
	}
}
