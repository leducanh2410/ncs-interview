import React from 'react';
import { Flex, Loader } from '@mantine/core';

type Props = {
    height: string | number;
};

const DBlockLoader: React.FC<Props> = ({ height }) => {
    return (
        <Flex h={height} w="100%" justify="center" align="center">
            <Loader />
        </Flex>
    );
};

export default DBlockLoader;
